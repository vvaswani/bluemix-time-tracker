<?php
use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;

// autoload files
require '../vendor/autoload.php';
require '../config.php';

// if VCAP_SERVICES environment available
// overwrite local credentials with environment credentials
if ($services = getenv("VCAP_SERVICES")) {
  $services_json = json_decode($services, true);
  $config['settings']['db']['hostname'] = $services_json['cleardb'][0]['credentials']['hostname'];
  $config['settings']['db']['username'] = $services_json['cleardb'][0]['credentials']['username'];
  $config['settings']['db']['password'] = $services_json['cleardb'][0]['credentials']['password'];
  $config['settings']['db']['name'] = $services_json['cleardb'][0]['credentials']['name'];
}

// configure Slim application instance
// initialize application
$app = new \Slim\App($config);

// initialize dependency injection container
$container = $app->getContainer();

// add view renderer to DI container
$container['view'] = function ($container) {
  return new \Slim\Views\PhpRenderer("../views/");
};

// configure and add MySQL client to DI container
$container['db'] = function ($container) {
  $config = $container->get('settings');
  return new mysqli(
    $config['db']['hostname'], 
    $config['db']['username'], 
    $config['db']['password'], 
    $config['db']['name']
  );
};

// welcome page controller
$app->get('/', function (Request $request, Response $response) {
  return $response->withHeader('Location', $this->router->pathFor('home'));
});

$app->get('/home', function (Request $request, Response $response) {
  $response = $this->view->render($response, 'home.phtml', [
    'router' => $this->router
  ]);
  return $response;
})->setName('home');

// project list controller
$app->get('/projects[/]', function (Request $request, Response $response) {
  // query for all project records
  $projects = $this->db->query("SELECT * FROM projects");
  $response = $this->view->render($response, 'projects/list.phtml', [
    'router' => $this->router, 'projects' => $projects
  ]);
  return $response;
})->setName('projects-list');

// project modification form renderer
$app->get('/projects/save[/{id}]', function (Request $request, Response $response, $args) {
  $project = null;
  if (isset($args['id'])) {
    $id = filter_var($args['id'], FILTER_SANITIZE_NUMBER_INT);
    $projectResult = $this->db->query("SELECT * FROM projects WHERE id = '$id'");
    $project = $projectResult->fetch_object();    
  }
  $response = $this->view->render($response, 'projects/save.phtml', [
    'router' => $this->router, 'project' => $project
  ]);
  return $response;
})->setName('projects-save');

// project modification controller
$app->post('/projects/save[/{id}]', function (Request $request, Response $response, $args) {
  // get configuration
  $config = $this->get('settings');
  // get input values
  $params = $request->getParams();
  // check input
  if (!($name = filter_var($params['name'], FILTER_SANITIZE_STRING))) {
    throw new Exception('ERROR: Project name is not a valid string');
  }
  // if project id included
  // check input and update record
  // if not, create new record
  if (!empty($params['id'])) {
    $id = filter_var($params['id'], FILTER_SANITIZE_NUMBER_INT);
    if (!(filter_var($id, FILTER_VALIDATE_INT))) {
      throw new Exception('ERROR: Project is not valid');
    }
    if (!$this->db->query("UPDATE projects SET name='$name' WHERE id='$id'")) {
      throw new Exception('Failed to save record: ' . $this->db->error);
    }
  } else {
    if (!$this->db->query("INSERT INTO projects (name) VALUES ('$name')")) {
      throw new Exception('Failed to save record: ' . $this->db->error);
    }
  }
  $response = $this->view->render($response, 'projects/save.phtml', [
    'router' => $this->router
  ]);
  return $response;
});

// project deletion controller
$app->get('/projects/delete/{id}', function (Request $request, Response $response, $args) {
  $id = filter_var($args['id'], FILTER_SANITIZE_NUMBER_INT);
  if (!$this->db->query("DELETE FROM entries WHERE pid = '$id'")) {
    throw new Exception('Failed to delete records.');
  }
  if (!$this->db->query("DELETE FROM projects WHERE id = '$id'")) {
    throw new Exception('Failed to delete record.');
  }
  return $response->withHeader('Location', $this->router->pathFor('projects-list'));
})->setName('projects-delete');

// time entry list controller
$app->get('/entries/{id:[0-9]+}[/{download}]', function (Request $request, Response $response, $args) {
  $id = filter_var($args['id'], FILTER_SANITIZE_NUMBER_INT);
  // query for project name
  $projectResult = $this->db->query("SELECT * FROM projects WHERE id = '$id'");
  $project = $projectResult->fetch_object();
  // query for all time entries
  $entries = $this->db->query("SELECT * FROM entries WHERE pid = '$id' ORDER BY date ASC");
  if (isset($args['download'])) {
    $response = $response->withHeader('Content-type', 'text/csv')
                         ->withHeader('Content-Disposition', 'attachment; filename="' . $id .'.csv"')
                         ->withHeader('Expires', '@0')
                         ->withHeader('Cache-Control', 'must-revalidate')
                         ->withHeader('Pragma', 'public');
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, $this->view->fetch('entries/list.csv', [
      'entries' => $entries
    ]));
    return $response->withBody(new \Slim\Http\Stream($stream));                         
  } else {
    $response = $this->view->render($response, 'entries/list.phtml', [
      'router' => $this->router, 'entries' => $entries, 'project' => $project
    ]);
    return $response;
  }
})->setName('entries-list');

// time entry form renderer
$app->get('/entries/save', function (Request $request, Response $response, $args) {
  // query for all project records
  $projects = $this->db->query("SELECT * FROM projects");
  $response = $this->view->render($response, 'entries/save.phtml', [
    'router' => $this->router, 'projects' => $projects
  ]);
  return $response;
})->setName('entries-save');

// time entry form processor
$app->post('/entries/save', function (Request $request, Response $response, $args) {
  // get configuration
  $config = $this->get('settings');
  // get input values
  $params = $request->getParams();
  // check input
  $pid = filter_var($params['pid'], FILTER_SANITIZE_NUMBER_INT);
  if (!(filter_var($pid, FILTER_VALIDATE_INT))) {
    throw new Exception('ERROR: Project is not valid');
  }
  $hours = filter_var($params['hours'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
  if (!(filter_var($hours, FILTER_VALIDATE_FLOAT))) {
    throw new Exception('ERROR: Time value is not a valid number');
  }
  $comment = filter_var($params['comment'], FILTER_SANITIZE_STRING);
  if (empty($comment)) {
    throw new Exception('ERROR: Comment is not valid');
  }  
  $date = $params['date'];
  if (!($date == date('Y-m-d', strtotime($date)))) {
    throw new Exception('ERROR: Date is not valid');
  }
  // save record
  if (!$this->db->query("INSERT INTO entries (pid, hours, comment, date) VALUES ('$pid', '$hours', '$comment', '$date')")) {
    throw new Exception('Failed to save record: ' . $this->db->error);
  }
  $response = $this->view->render($response, 'entries/save.phtml', [
    'router' => $this->router
  ]);
  return $response;
});

// time entry deletion controller
$app->get('/entries/delete/{id}', function (Request $request, Response $response, $args) {
  $id = filter_var($args['id'], FILTER_SANITIZE_NUMBER_INT);
  if (!$this->db->query("DELETE FROM entries WHERE id = '$id'")) {
    throw new Exception('Failed to delete record.');
  }
  return $response->withHeader('Location', $this->router->pathFor('projects-list'));
})->setName('entries-delete');


$app->get('/legal', function (Request $request, Response $response) {
  $response = $this->view->render($response, 'legal.phtml', [
    'router' => $this->router
  ]);
  return $response;
})->setName('legal');

$app->run();
