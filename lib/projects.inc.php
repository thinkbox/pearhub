<?php
require_once 'pdoext.inc.php';
require_once 'pdoext/connection.inc.php';
require_once 'pdoext/query.inc.php';
require_once 'pdoext/tablegateway.php';

class Accessor {
  public $errors = array();
  protected $row = array();
  function __construct($row = array()) {
    $this->row = $row;
  }
  function getArrayCopy() {
    return $this->row;
  }
  function __call($fn, $args) {
    if (preg_match('/^(g|s)et(.+)$/i', $fn, $reg)) {
      $mode = $reg[1];
      $camel_field = $reg[2];
    } else {
      $mode = 'g';
      $camel_field = $fn;
    }
    $colum = strtolower(preg_replace('/(?<=\\w)([A-Z])/', '_\\1', $camel_field));
    if ($mode === 'g') {
      return isset($this->row[$colum]) ? $this->row[$colum] : null;
    } else {
      $this->row[$colum] = $args[0];
    }
  }
}

/*
$x = new Accessor();
$x->setFoo('fooval');
var_dump($x);
var_dump($x->foo());
*/

class ProjectGateway extends pdoext_TableGateway {
  protected $sql_load_aggregates;
  protected $maintainers;
  function __construct(pdoext_Connection $db, MaintainerGateway $maintainers) {
    parent::__construct('projects', $db);
    $this->maintainers = $maintainers;
  }
  function load($row = array()) {
    $p = new Project($row);
    if ($row['id']) {
      return $this->loadAggregates($p);
    }
    return $p;
  }
  function loadAggregates($project) {
    if (!$this->sql_load_aggregates) {
      $this->sql_load_aggregates = $this->db->prepare(
        '
SELECT
  project_maintainers.type,
  maintainers.owner,
  maintainers.user,
  maintainers.name,
  maintainers.email,
  null as channel,
  null as version
FROM
  project_maintainers
LEFT JOIN
  maintainers
ON project_maintainers.user = maintainers.user
WHERE
  project_maintainers.project_id = :id1

UNION

SELECT
  null,
  null,
  null,
  null,
  null,
  channel,
  version
FROM
  dependencies
WHERE
  dependencies.project_id = :id2
'
      );
      $this->sql_load_aggregates->setFetchMode(PDO::FETCH_ASSOC);
    }
    $this->sql_load_aggregates->execute(
      array(
        'id1' => $project->id(),
        'id2' => $project->id()));
    foreach ($this->sql_load_aggregates as $row) {
      if ($row['name']) {
        $project->addProjectMaintainer(new ProjectMaintainer($this->maintainers->load($row), $row['type'], $project->id()));
      } elseif ($row['channel']) {
          $project->addDependency($row['channel'], $row['version']);
      }
    }
    return $project;
  }
  function insertAggregates($project) {
    $insert_project_maintainer = $this->db->prepare(
      'insert into project_maintainers (project_id, user, type) values (:project_id, :user, :type)');
    foreach ($project->projectMaintainers() as $pm) {
      $insert_project_maintainer->execute(
        array(
          ':project_id' => $project->id(),
          ':user' => $pm->maintainer()->user(),
          ':type' => $pm->type()
        ));
    }
    $insert_dependency = $this->db->prepare(
      'insert into dependencies (project_id, channel, version) values (:project_id, :channel, :version)');
    foreach ($project->dependencies() as $dep) {
      $insert_dependency->execute(
        array(
          ':project_id' => $project->id(),
          ':channel' => $dep['channel'],
          ':version' => $dep['version']
        ));
    }
  }
  function deleteAggregates($project) {
    $this->db->prepare(
      'delete from project_maintainers where project_id = :project_id'
    )->execute(
        array(
          ':project_id' => is_object($project) ? $project->id() : $project));
    $this->db->prepare(
      'delete from dependencies where project_id = :project_id'
    )->execute(
        array(
          ':project_id' => is_object($project) ? $project->id() : $project));
  }
  function updateAggregates($project) {
    $this->deleteAggregates($project);
    $this->insertAggregates($project);
    $this->db->exec(
      'delete from maintainers where user not in (select user from project_maintainers)');
  }
  function validateUpdate($project) {
    if (!$project->id()) {
      $project->errors['id'][] = "Missing id";
    }
  }
  function validate($project) {
    if (!$project->name()) {
      $project->errors['name'][] = "Missing name";
    } elseif (!preg_match('/^[a-z]+[a-z0-9_]+[a-z0-9]+$/i', $project->name()) || preg_match('/__/', $project->name())) {
      $project->errors['name'][] = 'Name is illegal. You can only use alphanumeric names. Separate with underscores.';
    }
    if (strlen($project->summary()) > 200) {
      $project->errors['summary'][] = "Summary is too long. It's supposed to be a one-liner.";
    }
    if (!$project->summary()) {
      $project->errors['summary'][] = "Summary missing.";
    }
    if (!$project->description()) {
      $project->errors['description'][] = "Description missing.";
    }
    if (!$project->repository()) {
      $project->errors['repository'][] = "Missing repository";
    }
    if (!preg_match('/^\d+\.\d+\.\d+$/', $project->phpVersion())) {
      $project->errors['php-version'][] = "Format of version must be X.X.X";
    }
    if (!$project->licenseTitle()) {
      $project->errors['license-title'][] = "Missing license";
    }
    if (!in_array($project->releasePolicy(), array('manual', 'auto'))) {
      $project->errors[] = "You must select a valid release policy";
    }
    $found = false;
    $names = array();
    foreach ($project->projectMaintainers() as $pm) {
      if ($pm->type() === 'lead') {
        $found = true;
      }
      if (!trim($pm->maintainer()->user())) {
        $project->errors['maintainers'][] = "Maintainer name is missing";
      }
      $names[] = $pm->maintainer()->user();
    }
    if (!$found) {
      $project->errors['maintainers'][] = "There must be at least one lead";
    }
    if (count(array_unique($names)) < count($names)) {
      $project->errors['maintainers'][] = "Each maintainer can only be entered once";
    }
    if (!trim($project->path())) {
      $project->errors['path'][] = "File path is missing";
    } elseif (!preg_match('~^/~', $project->path())) {
      $project->errors['path'][] = "File path must begin with a /";
    } elseif (!trim($project->destination())) {
      $project->errors['destination'][] = "File destination is missing";
    } elseif (!preg_match('~^/~', $project->destination())) {
      $project->errors['destination'][] = "File destination must begin with a /";
    }
    foreach ($project->dependencies() as $dep) {
      if (!trim($dep['channel'])) {
        $project->errors['dependencies'][] = "Dependency channel is missing";
      }
      if ($dep['version'] && !preg_match('/^\d+\.\d+\.\d+$/', $dep['version'])) {
        $project->errors['dependencies'][] = "Format of version must be X.X.X";
      }
    }
  }
  function insert($project) {
    if (!$project->created()) {
      $project->setCreated(date("Y-m-d H:i:s"));
    }
    try {
      $id = parent::insert($project);
    } catch (PDOException $ex) {
      if (preg_match('/Integrity constraint violation: 1062 Duplicate entry .* for key 2/', $ex->getMessage())) {
        $project->errors['name'] = 'There is already a project registered with that name.';
      } else {
        $project->errors[] = $ex->getMessage();
      }
      return false;
    }
    if ($id) {
      $project->setId($id);
      $this->insertAggregates($project);
    }
    return $id;
  }
  function update($project, $condition = null) {
    $res = parent::update($project, $condition);
    if ($res) {
      $this->updateAggregates($project);
    }
    return $res;
  }
  function delete($condition) {
    $result = parent::delete($condition);
    $this->deleteAggregates($condition['id']);
    return $result;
  }
  function selectWithAutomaticReleasePolicy() {
    $result = $this->db->query("select * from projects where release_policy = 'auto'");
    $result->setFetchMode(PDO::FETCH_ASSOC);
    return new pdoext_Resultset($result, $this);
  }
  function updateRevisionInfo($project_id, $release) {
    $this->db->pexecute(
      "update projects set latest_version = :latest_version where id = :project_id",
      array(
        ':latest_version' => $release ? $release->version() : null,
        ':project_id' => $project_id));
  }
}

class MaintainerGateway extends pdoext_TableGateway {
  function __construct(pdoext_Connection $db) {
    parent::__construct('maintainers', $db);
  }
  function load($row = array()) {
    return new Maintainer($row);
  }
}

class Project extends Accessor {
  protected $dependencies = array();
  protected $project_maintainers = array();
  function __construct($row = array('php_version' => '5.0.0', 'release_policy' => 'auto', 'path' => '/')) {
    parent::__construct($row);
  }
  function displayName() {
    return $this->name();
  }
  function repositoryLocation() {
    return new RepoLocation(
      $this->row['repository'],
      $this->row['repository_username'],
      $this->row['repository_password']);
  }
  function dependencies() {
    return $this->dependencies;
  }
  function projectMaintainers() {
    return $this->project_maintainers;
  }
  function setId($id) {
    if ($this->id() !== null) {
      throw new Exception("Can't change id");
    }
    foreach ($this->project_maintainers as $project_maintainer) {
      $project_maintainer->setProjectId($id);
    }
    return $this->row['id'] = $id;
  }
  function setDependencies($dependencies) {
    $this->dependencies = $dependencies;
  }
  function addDependency($channel, $version = null) {
    $this->dependencies[] = array('channel' => $channel, 'version' => $version);
  }
  function addProjectMaintainer(ProjectMaintainer $project_maintainer) {
    $project_maintainer->setProjectId($this->id());
    $this->project_maintainers[] = $project_maintainer;
    return $project_maintainer;
  }
  function setProjectMaintainers($project_maintainers = array()) {
    $this->project_maintainers = array();
    foreach ($project_maintainers as $pm) {
      $this->addProjectMaintainer($pm);
    }
  }
  function unmarshal($hash) {
    $h = array();
    foreach ($hash as $k => $v) {
      $h[str_replace('-', '_', $k)] = $v;
    }
    $hash = $h;
    $fields = array(
      'name', 'owner', 'created',
      'repository', 'repository_username', 'repository_password',
      'summary', 'description', 'href', 'license_title', 'license_href',
      'php_version', 'release_policy',
      'path', 'destination', 'ignore');
    foreach ($fields as $field) {
      if (array_key_exists($field, $hash)) {
        $this->{"set$field"}($hash[$field]);
      }
    }
    $this->setDependencies(array());
    if (isset($hash['dependencies'])) {
      foreach ($hash['dependencies'] as $row) {
          $this->addDependency(
              $row['channel'],
              isset($row['version']) ? $row['version'] : null);
      }
    }
  }
  function unmarshalMaintainers($body, $user, $maintainers) {
    $this->setProjectMaintainers(array());
    if (isset($body['maintainers'])) {
      foreach ($body['maintainers'] as $row) {
        $m = $maintainers->fetch(array('user' => $row['user']));
        if ($m) {
          if ($m->owner() == $user) {
            $m->setName($row['name']);
            $m->setEmail($row['email']);
          } elseif ($row['name'] !== $m->name() || $row['email'] !== $m->email()) {
            $this->errors['maintainers'][] = "You're not allowed to change details of " . $row['user'] . ".";
          }
        } else {
          $m = new Maintainer(
            array(
              'user' => $row['user'],
              'name' => $row['name'],
              'email' => $row['email'],
              'owner' => $user));
        }
        $this->addProjectMaintainer(new ProjectMaintainer($m, $row['type']));
      }
    }
    return empty($this->errors['maintainers']);
  }
}

class ProjectMaintainer {
  protected $project_id;
  protected $maintainer;
  protected $type;
  function __construct($maintainer, $type, $project_id = null) {
    if (!in_array($type, array('lead', 'developer', 'contributor', 'helper'))) {
      throw new Exception("Illegal value for 'type'");
    }
    $this->maintainer = $maintainer;
    $this->type = $type;
    $this->project_id = $project_id;
  }
  function setProjectId($project_id) {
    if ($this->projectId() !== null && $project_id !== $this->projectId()) {
      throw new Exception("Can't change project_id");
    }
    $this->project_id = $project_id;
  }
  function projectId() {
    return $this->project_id;
  }
  function maintainer() {
    return $this->maintainer;
  }
  function type() {
    return $this->type;
  }
}

class Maintainer extends Accessor {
  function displayName() {
    return $this->user();
  }
  function setId($id) {
    if ($this->id() !== null) {
      throw new Exception("Can't change id");
    }
    return $this->row['id'] = $id;
  }
}

class ReleaseGateway extends pdoext_TableGateway {
  protected $project_gateway;
  function __construct(pdoext_Connection $db, ProjectGateway $project_gateway) {
    parent::__construct('releases', $db);
    $this->project_gateway = $project_gateway;
  }
  function load($row = array()) {
    return new Release($row);
  }
  function validate($release) {
    if (!$release->projectId()) {
      $release->errors['project_id'][] = "Missing project_id";
    }
    if (!in_array($release->mode(), array('auto', 'manual'))) {
      $release->errors['mode'][] = "Illegal value";
    }
    if (!$release->version()) {
      $release->errors['version'][] = "Missing version";
    } elseif (!preg_match('/^\d+\.\d+\.\d+$/', $release->version())) {
      $release->errors['version'][] = "Format of version must be X.X.X";
    }
    if (!in_array($release->status(), array('building', 'completed', 'failed'))) {
      $release->errors['status'][] = "Illegal value";
    }
  }
  /**
   * @param $project integer | Project
   */
  function lastReleaseFor($project) {
    $result = $this->db->pexecute(
      "select * from releases where project_id = :project_id order by created desc limit 1",
      array(':project_id' => is_object($project) ? $project->id() : $project));
    $row = $result->fetch(PDO::FETCH_ASSOC);
    return $row ? $this->load($row) : null;
  }
  function selectByProject($project) {
    $result = $this->db->pexecute(
      "select * from releases where project_id = :project_id order by created desc",
      array(':project_id' => $project->id()));
    $result->setFetchMode(PDO::FETCH_ASSOC);
    return new pdoext_Resultset($result, $this);
  }
  function insert($release) {
    if (!$release->created()) {
      $release->setCreated(date("Y-m-d H:i:s"));
    }
    $result = parent::insert($release);
    $this->project_gateway->updateRevisionInfo(
      $release->projectId(),
      $this->lastReleaseFor($release->projectId()));
    return $result;
  }
  function update($release, $condition = null) {
    $result = parent::update($release, $condition);
    $this->project_gateway->updateRevisionInfo(
      $release->projectId(),
      $this->lastReleaseFor($release->projectId()));
    return $result;
  }
  function delete($condition) {
    $result = parent::delete($condition);
    $project_id = is_array($condition) ? $condition['project_id'] : $release->projectId();
    $this->project_gateway->updateRevisionInfo(
      $project_id,
      $this->lastReleaseFor($project_id));
    return $result;
  }
  function selectPendingBuild() {
    $result = $this->db->query("select * from releases where status = 'building' or status = 'failed'");
    $result->setFetchMode(PDO::FETCH_ASSOC);
    return new pdoext_Resultset($result, $this);
  }
}

class Release extends Accessor {
  function __construct($row = array('project_id' => null, 'version' => null, 'status' => 'building', 'created' => null, 'mode' => 'auto')) {
    parent::__construct($row);
  }
  function setBuilding() {
    $this->row['status'] = 'building';
    $this->row['fail_reason'] = null;
  }
  function setCompleted() {
    $this->row['status'] = 'completed';
    $this->row['fail_reason'] = null;
  }
  function setFailed($reason) {
    $this->row['status'] = 'failed';
    $this->row['fail_reason'] = $reason;
  }
}
