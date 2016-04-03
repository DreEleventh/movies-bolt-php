<?php

use Silex\Application;
use Symfony\Component\HttpFoundation\Request,
    Symfony\Component\HttpFoundation\JsonResponse,
    Symfony\Component\Yaml\Yaml;
use GraphAware\Neo4j\Client\ClientBuilder;

require __DIR__.'/vendor/autoload.php';

$app = new Application();

if (false !== getenv('GRAPHSTORY_URL')) {
    $cnx = getenv('GRAPHSTORY_URL');
} else {
    $config = Yaml::parse(file_get_contents(__DIR__.'/config/config.yml'));
    $cnx = $config['neo4j_url'];
}

$neo4j = ClientBuilder::create()
    ->addConnection('default', $cnx)
    ->build();

$app->get('/', function () {
    return file_get_contents(__DIR__.'/static/index.html');
});

$app->get('/graph', function (Request $request) use ($neo4j) {
    $limit = $request->get('limit', 50);
    $params = ['limit' => $limit];
    $query = 'MATCH (m:Movie)<-[r:ACTED_IN]-(p:Person) RETURN m,r,p LIMIT {limit}';
    $result = $neo4j->run($query, $params);

    $nodes = [];
    $edges = [];
    $identityMap = [];

    foreach ($result->records() as $record){
        $nodes[] = [
            'title' => $record->get('m')->value('title'),
            'label' => $record->get('m')->labels()[0]
        ];
        $identityMap[$record->get('m')->identity()] = count($nodes)-1;
        $nodes[] = [
            'title' => $record->get('p')->value('name'),
            'label' => $record->get('p')->labels()[0]
        ];
        $identityMap[$record->get('p')->identity()] = count($nodes)-1;

        $edges[] = [
            'source' => $identityMap[$record->get('r')->startNodeIdentity()],
            'target' => $identityMap[$record->get('r')->endNodeIdentity()]
        ];
    }

    $data = [
        'nodes' => $nodes,
        'links' => $edges
    ];

    $response = new JsonResponse();
    $response->setData($data);

    return $response;
});

$app->get('/search', function (Request $request) use ($neo4j) {
    $searchTerm = $request->get('q');
    $term = '(?i).*'.$searchTerm.'.*';
    $query = 'MATCH (m:Movie) WHERE m.title =~ {term} RETURN m';
    $params = ['term' => $term];

    $result = $neo4j->run($query, $params);
    $movies = [];
    foreach ($result->records() as $record){
        $movies[] = ['movie' => $record->get('m')->values()];
    }

    $response = new JsonResponse();
    $response->setData($movies);

    return $response;
});

$app->get('/movie/{title}', function ($title) use ($neo4j) {
    $query = 'MATCH (m:Movie) WHERE m.title = {title} OPTIONAL MATCH p=(m)<-[r]-(a:Person) RETURN m, collect({rel: r, actor: a}) as plays';
    $params = ['title' => $title];

    $result = $neo4j->run($query, $params);

    $movie = $result->firstRecord()->get('m');
    $mov = [
        'title' => $movie->value('title'),
        'cast' => []
        ];

    foreach ($result->firstRecord()->get('plays') as $play) {
        $actor = $play['actor']->value('name');
        $job = explode('_', strtolower($play['rel']->type()))[0];
        $mov['cast'][] = [
            'job' => $job,
            'name' => $actor,
            'role' => array_key_exists('roles', $play['rel']->values()) ? $play['rel']->value('roles') : null
        ];
    }

    $response = new JsonResponse();
    $response->setData($mov);

    return $response;
});

$app->get('/import', function () use ($app, $neo4j) {
    $query = trim(file_get_contents(__DIR__.'/static/movies.cypher'));
    $neo4j->run($query);

    return $app->redirect('/');
});

$app->get('/reset', function() use ($app, $neo4j) {
    $query = 'MATCH (n) OPTIONAL MATCH (n)-[r]-() DELETE r,n';
    $neo4j->run($query);

    return $app->redirect('/import');

});

$app->run();