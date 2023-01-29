<?php
    require_once("vendor/autoload.php");

    // $conn   = new phpari("hello-world"); //create new object
    // $sounds = new sounds($conn);

    // echo json_encode($sounds->endpoints_list());
    // exit(0);
    // $conn         = new phpari("hello-world"); //create new object
    // $cEndPoints = new endpoints($conn);
    // $response   = $cEndPoints->endpoints_list();

    // echo json_encode($response);
    // exit(0);

    // Crea una conexión con el servidor de Asterisk
    $conn     = new phpari("hello-world-channel"); //create new object
    $channels = new channels($conn);
    $channel = $channels->channel_originate(
        'SIP/2222',
        NULL,
        array(
            "extension"      => "2222"
        )
    );

    echo json_encode($channel);

    $stasisEvents = $conn->stasisEvents;
    // exit(0);

    // var_dump($channel);
    // Agrega un evento de marcado
    $stasisEvents->on('ChannelDtmfReceived', function ($channel, $event) {
        echo "El número marcado es: " . $event->digit . "\n";
    });

    // Agrega un evento de colgado de llamada
    $stasisEvents->on('StasisEnd', function ($channel, $event) {
        $channel->hangup();
    });

    // Espera a que la llamada termine
    // $channel->wait();
?>
