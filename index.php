<?php

use Slim\Psr7\Response;
use Slim\Psr7\Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Factory\AppFactory;
use Firebase\JWT\JWT; 
use Firebase\JWT\Key; 
use Slim\Routing\RouteCollectorProxy;
use Slim\Routing\RouteContext; 

require 'vendor/autoload.php';

// Load the .env file to get the key
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__); 
$dotenv->load();

$secretKey = $_ENV['SECRET_KEY'];

// Connect to the db
$db = new SQLite3('chat.db');

// Create slim app 
$app = AppFactory::create();


// Decodes the bearer token provided by user 
// Returns -1 for invalid token 
$tokenDecode = function (Request $request) use ($secretKey){
    try{
        $authHeader = $request->getHeaderLine('Authorization');
        $tokenParts = explode(' ', $authHeader);
        $token = $tokenParts[1];
        $decodedToken = JWT::decode($token, new Key($secretKey, 'HS256')); 
        $userId = $decodedToken->id;
        return $userId; 
    }
    catch (UnexpectedValueException){
        return -1;
    }
};

// This part is implemented for testing purposes
$app->get('/protected/{user_id}', function (Request $request, Response $response, array $args) use ($db, $secretKey){
    $userId = $args['user_id']; 

    $query = 'SELECT * FROM users WHERE id = :user_id;'; 
    $stmt = $db->prepare($query);
    $stmt->bindValue('user_id', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $result = $result->fetchArray(SQLITE3_ASSOC); 
    
    $token = JWT::encode($result, $secretKey, 'HS256');
    $response->getBody()->write($token);
    
    return $response;
});

// Return all the groups data
$app->get('/groups',  function (Request $request, Response $response, array $args) use ($db){

    $response = $response->withHeader('Content-Type', 'application/json');
    try{ 
        $query = 'SELECT * FROM groups;';
        $stmt = $db->prepare($query); 
        $result = $stmt->execute();  
        $groups = [];

        while ($group = $result->fetchArray(SQLITE3_ASSOC)){
            $groups[] = $group; 
        }

        $response->getBody()->write(json_encode($groups));
        return $response;

    }
    catch(Exception $e){
        $response = $response->withStatus(500); 
        $response = $response->getBody()->write(json_encode(['error' => 'Internal Server Error']));
        return $response;
    }
}); 

$app->post('/groups/create', function (Request $request, Response $response, array $args) use ($db, $tokenDecode){
    $response = $response->withHeader('Content-Type', 'application/json');
    $data = $request->getParsedBody(); 
    $groupName = $data['groupname']; 

    $userId = $tokenDecode($request);

    if($userId == -1){
        $response = $response->withStatus(401); 
        $response->getBody()->write(json_encode(['error' => 'Unauthorized']));
        return $response; 
    }

    try{
        // Create the group
        $query = "INSERT INTO groups (groupname) VALUES (:groupName)"; 
        $stmt = $db->prepare($query); 
        $stmt->bindValue('groupName', $groupName, SQLITE3_TEXT); 
        $stmt->execute(); 

        // Get the groupid of the group just created 
        // This implementation relies on the fact that latest 
        // created group will have the max id among. 
        // Since the db implementation was very simple 
        // and there can be multiple entries with same groupname
        $query = "SELECT id FROM groups WHERE groupname = :groupName"; 
        $stmt = $db->prepare($query); 
        $stmt->bindValue('groupName', $groupName, SQLITE3_TEXT); 
        $result = $stmt->execute(); 
        $result = $result->fetchArray(SQLITE3_ASSOC);
        $groupId = max($result);
        
        // Insert the new relation to users_groups table
        $query = "INSERT INTO users_groups (user_id, group_id) VALUES (:userId, :groupId)";
        $stmt = $db->prepare($query);
        $stmt->bindValue('userId', $userId);
        $stmt->bindValue('groupId', $groupId);
        $stmt->execute(); 

        $response = $response->withStatus(201); 
        return $response; 
    }
    catch(Exception $e){
        $response = $response->withStatus(500); 
        $response = $response->getBody()->write(json_encode(['error' => 'Internal Server Error']));
    }
    return $response; 
});


$app->group('/groups/{group_id}', function (RouteCollectorProxy $group) use ($db, $tokenDecode){

    // Middleware for determining if a user is already in the group. 
    // Implemented this part to deliver the project as secure as possible without login.
    $userGroupAuth = function (Request $request,  RequestHandler $handler) use ($db, $tokenDecode){

        // Seems like can't give $args as a parameter to middleware.
        // Using this method instead.
        $routeContext = RouteContext::fromRequest($request); 
        $route = $routeContext->getRoute(); 
        $groupId = $route->getArgument('group_id');

        // Create a new response 
        $response = new Response();
        $response = $response->withHeader('Content-Type', 'application/json');

        // Get the necessary data from request
        $userId = $tokenDecode($request); 
        if($userId == -1){
            // invalid token 
            $response = $response->withStatus(401);
            $response->getBody()->write(json_encode(['error' => 'Unauthorized']));
            return $response; 
        }

        try {
            // Check if the user is in the requested group 
            $query = "SELECT COUNT (*) AS count FROM users_groups WHERE user_id = :userId AND group_id = :groupId;"; 
            $stmt = $db->prepare($query); 
            $stmt->bindValue('userId', $userId, SQLITE3_INTEGER);
            $stmt->bindValue('groupId', $groupId, SQLITE3_INTEGER);
            $result = $stmt->execute(); 
            $row = $result->fetchArray(SQLITE3_ASSOC);

            // The user is not in the group, but the token is valid.
            // Return forbidden with status 403.
            if ($row['count'] == 0) {
                $response = $response->withStatus(403);
                $response->getBody()->write(json_encode(['error' => 'Forbidden']));
                return $response;
            }
            
            $request = $request->withAttribute('user_id', $userId);
            return $handler->handle($request);
        }
        catch (Exception $e) {
            $response = $response->withStatus(500);
            $response->getBody()->write(json_encode(['error' => 'Internal Server Error']));
            return $response;
        }
    };

    // This middleware is pretty much the reverse for userGroupAuth. 
    // I'm not sure doing it this way is the best idea, but this solution is surely stateless.
    $joinGroupAuth = function (Request $request, RequestHandler $handler) use ($db, $tokenDecode){

        $routeContext = RouteContext::fromRequest($request); 
        $route = $routeContext->getRoute(); 
        $groupId = $route->getArgument('group_id');

        $response = new Response();
        $response = $response->withHeader('Content-Type', 'application/json');

        // Get the necessary data from request
        $userId = $tokenDecode($request); 
        if($userId == -1){
            // invalid token 
            $response = $response->withStatus(401);
            $response->getBody()->write(json_encode(['error' => 'Unauthorized']));
            return $response; 
        }

        try{
            $query = "SELECT COUNT (*) AS count FROM users_groups WHERE user_id = :userId AND group_id = :groupId;"; 
            $stmt = $db->prepare($query); 
            $stmt->bindValue('userId', $userId, SQLITE3_INTEGER);
            $stmt->bindValue('groupId', $groupId, SQLITE3_INTEGER);
            $result = $stmt->execute(); 
            $row = $result->fetchArray(SQLITE3_ASSOC);

            // The user is already in the group, the token is valid.
            if ($row['count'] == 1) {
                $response = $response->withStatus(403);
                $response->getBody()->write(json_encode(['error' => 'Forbidden']));
                return $response;
            }
            
            $request = $request->withAttribute('user_id', $userId);
            return $handler->handle($request);
        }

        catch (Exception $e){
            $response = $response->withStatus(500);
            $response->getBody()->write(json_encode(['error' => 'Internal Server Error']));
            return $response;
        }
    };

    $group->get('/messages', function (Request $request, Response $response, array $args) use ($db){
        // Get the messages of the specific group. 

        $response = $response->withHeader('Content-Type', 'application/json'); 
        $groupId = $args['group_id'];

        try{
            $query = "SELECT * FROM messages WHERE group_id = :groupId"; 
            $stmt = $db->prepare($query); 
            $stmt->bindValue('groupId', $groupId, SQLITE3_INTEGER); 
            $result = $stmt->execute();

            
            $messages = [];

            while ($message = $result->fetchArray(SQLITE3_ASSOC)){
                $messages[] = $message; 
            }

            $response->getBody()->write(json_encode($messages)); 
        }
        catch (Exception $e){
            $response = $response->withStatus(500);
            $response->getBody()->write(json_encode(['error' => 'Internal Server Error']));
        }
        return $response;

    })->add($userGroupAuth);

    $group->post('/messages', function (Request $request, Response $response, array $args) use ($db){
        // Send a message to the group.

        $response = $response->withHeader('Content-Type', 'application/json'); 

        try{
            $groupId = $args['group_id']; 
            $userId = $request->getAttribute('user_id'); 
            $data = $request->getParsedBody(); 
            $messageText = $data['message']; 


            $query = "INSERT INTO messages (group_id, user_id, message) 
                        VALUES (:groupId, :userId, :message)";

            $stmt = $db->prepare($query); 
            $stmt->bindValue('groupId', $groupId, SQLITE3_INTEGER); 
            $stmt->bindValue('userId', $userId, SQLITE3_INTEGER); 
            $stmt->bindValue('message', $messageText, SQLITE3_TEXT); 
            $stmt->execute();

            // Return with the created response code.
            $response = $response->withStatus(201); 
        }
        catch(Exception $e){
            $response = $response->withStatus(500);
            $response->getBody()->write(json_encode(['error' => 'Internal Server Error']));
        }

        return $response;

    })->add($userGroupAuth);

    $group->post('/join', function (Request $request, Response $response, array $args) use ($db){

        $response = $response->withHeader('Content-Type', 'application/json'); 

        try{
            $userId = $request->getAttribute('user_id'); 
            $groupId = $args['group_id'];

            $query = 'INSERT INTO users_groups (user_id, group_id) VALUES (:userId, :groupId)'; 
            $stmt = $db->prepare($query); 
            $stmt->bindValue('userId', $userId, SQLITE3_INTEGER); 
            $stmt->bindValue('groupId', $groupId, SQLITE3_INTEGER); 
            $stmt->execute();

            $response = $response->withStatus(201);
        }
        catch (Exception $e){
            $response = $response->withStatus(500); 
            $response->getBody()->write(json_encode(['error' => 'Internal Server Error'])); 

        }

        return $response; 

    })->add($joinGroupAuth);

    $group->delete('/leave', function (Request $request, Response $response, array $args) use ($db){

        $response = $response->withHeader('Content-Type', 'application/json'); 
        $groupId = $args['group_id']; 
        $userId = $request->getAttribute('user_id');

        try{
            $query = "DELETE FROM users_groups WHERE user_id = :userId AND group_id = :groupId"; 
            $stmt = $db->prepare($query); 
            $stmt->bindValue('userId', $userId, SQLITE3_INTEGER); 
            $stmt->bindValue('groupId', $groupId, SQLITE3_INTEGER); 
            $stmt->execute(); 

            $response = $response->withStatus(204); 
        }

        catch (Exception $e){
            $response = $response->withStatus(500); 
            $response->getBody()->write(json_encode(['error' => 'Internal Server Error'])); 
        }

        return $response;

    })->add($userGroupAuth);
});



$app->run();
