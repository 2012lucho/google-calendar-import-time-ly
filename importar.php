<?php
require __DIR__ . '/vendor/autoload.php';

if (php_sapi_name() != 'cli') {
    throw new Exception('This application must be run on the command line.');
}

function csv_to_array($filename='', $delimiter=',')
{
    if(!file_exists($filename) || !is_readable($filename))
        return FALSE;

    $header = NULL;
    $data = array();
    if (($handle = fopen($filename, 'r')) !== FALSE)
    {
        while (($row = fgetcsv($handle, 1000, $delimiter)) !== FALSE)
        {
            if(!$header)
                $header = $row;
            else
                $data[] = array_combine($header, $row);
        }
        fclose($handle);
    }
    return $data;
}

$eventos = csv_to_array(__DIR__ .'/wp_ai1ec_events.csv',';');
$posts = csv_to_array(__DIR__ .'/wp_posts.csv',';');

//se sacan del arreglo de posts los que no se corresponden con el plugin de calendario
$aux = [];
for ($c=0; $c < count($posts);$c++){
  if ($posts[$c]['post_type'] == 'ai1ec_event'){ $aux[] = $posts[$c]; }
}
$posts = $aux;

//en los eventos se agrega el contenido de los posts
for ($c=0; $c<count($eventos); $c++){
  for ($j=0; $j<count($posts); $j++){
    if ($posts[$j]['ID'] == $eventos[$c]['post_id']){
      $eventos[$c]['post_content'] = $posts[$j]['post_content'];
      $eventos[$c]['post_title']   = $posts[$j]['post_title'];
    }
  }
}

//var_dump(date("c", 1415275200));die();

/**
 * Returns an authorized API client.
 * @return Google_Client the authorized client object
 */
function getClient()
{
    $client = new Google_Client();
    $client->setApplicationName('Pulpería Quilapan Calendar');
    $client->setScopes(Google_Service_Calendar::CALENDAR_EVENTS);
    $client->setAuthConfig( __DIR__ .'/credentials.json');
    $client->setAccessType('offline');
    $client->setPrompt('select_account consent');

    // Load previously authorized token from a file, if it exists.
    // The file token.json stores the user's access and refresh tokens, and is
    // created automatically when the authorization flow completes for the first
    // time.
    $tokenPath =  __DIR__ .'/token.json';
    if (file_exists($tokenPath)) {
        $accessToken = json_decode(file_get_contents($tokenPath), true);
        $client->setAccessToken($accessToken);
    }

    // If there is no previous token or it's expired.
    if ($client->isAccessTokenExpired()) {
        // Refresh the token if possible, else fetch a new one.
        if ($client->getRefreshToken()) {
            $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
        } else {
            // Request authorization from the user.
            $authUrl = $client->createAuthUrl();
            printf("Open the following link in your browser:\n%s\n", $authUrl);
            print 'Enter verification code: ';
            $authCode = trim(fgets(STDIN));

            // Exchange authorization code for an access token.
            $accessToken = $client->fetchAccessTokenWithAuthCode($authCode);
            $client->setAccessToken($accessToken);

            // Check to see if there was an error.
            if (array_key_exists('error', $accessToken)) {
                throw new Exception(join(', ', $accessToken));
            }
        }
        // Save the token to a file.
        if (!file_exists(dirname($tokenPath))) {
            mkdir(dirname($tokenPath), 0700, true);
        }
        file_put_contents($tokenPath, json_encode($client->getAccessToken()));
    }
    return $client;
}

// Get the API client and construct the service object.
$client = getClient();
$service = new Google_Service_Calendar($client);

$calendarId = 'p5in71rqpsullmm1j2pchi90m4@group.calendar.google.com';

//se recorre el arreglo de eventos y se agregan al calendario
for ($c=0; $c<count($eventos); $c++){
  $event = new Google_Service_Calendar_Event(array(
    'summary' => $eventos[$c]['post_title'],
    'location' => $eventos[$c]['address'],
    'description' => $eventos[$c]['post_content'],
    'start' => [
      'dateTime' => date("c", $eventos[$c]['start']),
      'timeZone' => 'America/Argentina/Buenos_Aires',
    ],
    'end' => [
      'dateTime' => date("c", $eventos[$c]['end']),
      'timeZone' => 'America/Argentina/Buenos_Aires',
    ],
    'attendees' => [],
    'reminders' => [
      'useDefault' => FALSE,
      'overrides'  => [],
    ],
  ));

  if ($eventos[$c]['recurrence_rules'] != '') $event->setRecurrence('RRULE:'.$eventos[$c]['recurrence_rules']);

  $event = $service->events->insert($calendarId, $event);
  print "Evento creado: ".$c;
}



// Print the next 10 events on the user's calendar.
$optParams = array(
  'maxResults' => 10,
  'orderBy' => 'startTime',
  'singleEvents' => true,
  'timeMin' => date('c'),
);
$results = $service->events->listEvents($calendarId, $optParams);
$events = $results->getItems();

if (empty($events)) {
    print "No upcoming events found.\n";
} else {
    print "Upcoming events:\n";
    foreach ($events as $event) {
        $start = $event->start->dateTime;
        if (empty($start)) {
            $start = $event->start->date;
        }
        printf("%s (%s)\n", $event->getSummary(), $start);
    }
}
