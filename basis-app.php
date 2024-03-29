
<?php
require_once("vendor/autoload.php");

class Dial
{
    
    public $stasisLogger;
    private $ariEndpoint;
    private $stasisClient;
    private $stasisLoop;
    private $phpariObject;
    private $stasisChannelID;
    private $dtmfSequence = "";
    private $channelStorage = array();
    
    public function __construct($appname = NULL)
    {
        try {
            if (is_null($appname))
                throw new Exception("[" . __FILE__ . ":" . __LINE__ . "] Stasis application name must be defined!", 500);
            
            $this->phpariObject = new phpari($appname, 'phpari.ini');
            
            $this->ariEndpoint = $this->phpariObject->ariEndpoint;
            $this->stasisClient = $this->phpariObject->stasisClient;
            $this->stasisLoop = $this->phpariObject->stasisLoop;
            $this->stasisLogger = $this->phpariObject->stasisLogger;
            $this->stasisEvents = $this->phpariObject->stasisEvents;
            
        } catch (Exception $e) {
            echo $e->getMessage();
            exit(99);
        }
    }
    
    public function setDtmf($digit = NULL)
    {
        try {
            
            $this->dtmfSequence .= $digit;
            
            return TRUE;
            
        } catch (Exception $e) {
            return FALSE;
        }
    }
    
    // process stasis events
    public function StasisAppEventHandler()
    {
        $this->stasisEvents->on('StasisStart', function ($event) {
            
            $this->stasisLogger->notice("Event received: StasisStart");
            
            $args = $event->args;
            if (isset($args[0])) {
                $this->stasisChannelID = $event->channel->id;
                $this->stasisLogger->notice("Creating new instance in channelStorage");
                $this->stasisLogger->notice("channelStorage: " . print_r($this->channelStorage, TRUE));
                $this->stasisLogger->notice("About to originate a call to another party, and bridge to us");
                $response = $this->phpariObject->channels()->channel_originate(
                    $args[0],
                    NULL,
                    array(
                        "app"	 => "stasis-dial",
                        "appArgs" => '',
                        "timeout" => $args[1]
                    )
                );
                
                /* Creating a Bridge resource */
                $this->phpariObject->bridges()->create('mixing', 'bridge_' . $response['id']);
                
                /* Populate the Storage */
                $this->channelStorage[$response['id']]['epoch'] = time();
                $this->channelStorage[$response['id']]['bridge'] = "bridge_" . $response['id'];
                $this->channelStorage[$response['id']]['A'] = $event->channel->id;
                $this->channelStorage[$response['id']]['B'] = $response['id'];
                $this->channelStorage[$event->channel->id]['bridge'] = "bridge_" . $response['id'];
                $this->channelStorage[$event->channel->id]['B'] = $event->channel->id;
                $this->channelStorage[$event->channel->id]['A'] = $response['id'];
                
                /* Join the bridge */
                $this->phpariObject->channels()->channel_ringing_start($event->channel->id);
                
            } else {
                $this->stasisLogger->notice("First channel is joinging the bridge: " . $event->channel->id . " -> bridge_" . $response['id']);
                $this->phpariObject->channels()->channel_ringing_stop($this->channelStorage[$event->channel->id]['A']);
                $this->phpariObject->channels()->channel_answer($this->channelStorage[$event->channel->id]['A']);
                $this->phpariObject->bridges()->addchannel($this->channelStorage[$event->channel->id]['bridge'], $this->channelStorage[$event->channel->id]['A']);
                $this->stasisLogger->notice("Second channel is joining the bridge: " . $event->channel->id . " -> bridge_" . $event->channel->id);
                $this->phpariObject->channels()->channel_answer($event->channel->id);
                $this->phpariObject->bridges()->addchannel($this->channelStorage[$event->channel->id]['bridge'], $event->channel->id);
            }
        });
        
        $this->stasisEvents->on('StasisEnd', function ($event) {
            /*
             * The following section will produce an error, as the channel no longer exists in this state - this is intentional
             */
            $this->stasisLogger->notice("Event received: StasisEnd");
            if (isset($this->channelStorage[$event->channel->id])) {
                $this->stasisLogger->notice("channelStorage: " . print_r($this->channelStorage, TRUE));
                
                $this->stasisLogger->notice("Terminating: " . $event->channel->id);
                $this->phpariObject->channels()->channel_delete($event->channel->id);
                
                $this->stasisLogger->notice("Terminating: " . $this->channelStorage[$event->channel->id]['A']);
                $this->phpariObject->channels()->channel_delete($this->channelStorage[$event->channel->id]['A']);
                
                $this->stasisLogger->notice("Terminating: " . $this->channelStorage[$event->channel->id]['bridge']);
                $this->phpariObject->bridges()->terminate($this->channelStorage[$event->channel->id]['bridge']);
                
                unset($this->channelStorage[$this->channelStorage[$event->channel->id]['A']]);
                unset($this->channelStorage[$event->channel->id]);
                $this->stasisLogger->notice("channelStorage: " . print_r($this->channelStorage, TRUE));
            }
        });
    }
    
    public function StasisAppConnectionHandlers()
    {
        try {
            $this->stasisClient->on("request", function ($headers) {
                $this->stasisLogger->notice("Request received!");
            });
            
            $this->stasisClient->on("handshake", function () {
                $this->stasisLogger->notice("Handshake received!");
            });
            
            $this->stasisClient->on("message", function ($message) {
                $this->stasisLogger->notice("channelStorage: " . print_r($this->channelStorage, TRUE));
                $event = json_decode($message->getData());
                $this->stasisLogger->notice('Received event: ' . $event->type);
                $this->stasisEvents->emit($event->type, array($event));
            });
            
        } catch (Exception $e) {
            echo $e->getMessage();
            exit(99);
        }
    }
    
    public function execute()
    {
        try {
            $this->stasisClient->open();
            $this->stasisLoop->run();
        } catch (Exception $e) {
            echo $e->getMessage();
            exit(99);
        }
    }
    
}

$basicAriClient = new Dial("stasis-dial");

$basicAriClient->stasisLogger->info("Starting Stasis Program... Waiting for handshake...");
$basicAriClient->StasisAppEventHandler();

$basicAriClient->stasisLogger->info("Initializing Handlers... Waiting for handshake...");
$basicAriClient->StasisAppConnectionHandlers();

$basicAriClient->stasisLogger->info("Connecting... Waiting for handshake...");
$basicAriClient->execute();

exit(0);