<?php

/**
 * PubSubHubbub publishing
 *
 * @package idno
 * @subpackage core
 */

namespace Idno\Core {

    class PubSubHubbub extends \Idno\Common\Component {

        function init() {
            
        }

        function registerEventHooks() {

            // Hook into the "saved" event to publish to PuSH when an entity is saved
            \Idno\Core\site()->addEventHook('saved', function (\Idno\Core\Event $event) {
                if ($object = $event->data()['object']) {
                    /* @var \Idno\Common\Entity $object */
                    if ($object->isPublic()) {
                        $url = $object->getURL();
                        \Idno\Core\PubSubHubbub::publish($url);
                    }
                }
            });
            
            // When we follow a user, try and subscribe to their hub
            \Idno\Core\site()->addEventHook('follow', function(\Idno\Core\Event $event) {

                $user = $event->data()['user'];
                $following = $event->data()['following'];

                if (($user instanceof \Idno\Entities\User) && ($following instanceof \Idno\Entities\User)) {

                    $url = $following->getURL();

                    // Find self reference from profile url
                    if ($feed = $this->findSelf($url)) {
                        $following->pubsub_self = $feed;

                        if ($hubs = $this->discoverHubs($url)) {

                            $pending = unserialize($user->pubsub_pending);
                            if (!$pending)
                                $pending = new \stdClass ();
                            if (!is_array($pending->subscribe))
                                $pending->subscribe = [];

                            $pending->subscribe[] = $following->getUUID();
                            $user->pubsub_pending = serialize($pending);
                            $user->save();
                            
                            $following->pubsub_hub = $hubs[0];
                            $following->save();

                            $return = \Idno\Core\Webservice::post($following->pubsub_hub, [
                                'hub.callback' => \Idno\Core\site()->config->url . 'pubsub/callback/' . $user->getID() . '/' . $following->getID(), // Callback, unique to each subscriber
                                'hub.mode' => 'subscribe',
                                'hub.verify' => 'async', // Backwards compatibility with v0.3 hubs
                                'hub.topic' => $feed, // Subscribe to rss
                            ]);
                            
                            error_log("Pubsub: " . print_r($return, true));
                        }
                        else
                            error_log("Pubsub: No hubs found");
                    } 
                }
            });

            // Send unfollow notification to their hub
            \Idno\Core\site()->addEventHook('unfollow', function(\Idno\Core\Event $event) {

                $user = $event->data()['user'];
                $following = $event->data()['following'];

                if (($user instanceof \Idno\Entities\User) && ($following instanceof \Idno\Entities\User)) {

                    $url = $following->getURL();

                    $pending = unserialize($user->pubsub_pending);
                    if (!$pending)
                        $pending = new \stdClass ();
                    if (!is_array($pending->subscribe))
                        $pending->unsubscribe = [];

                    $pending->unsubscribe[] = $following->getID();
                    $user->pubsub_pending = serialize($pending);
                    $user->save();

                    $return = \Idno\Core\Webservice::post($following->pubsub_hub, [
                        'hub.callback' => \Idno\Core\site()->config->url . 'pubsub/callback/' . $user->getID() . '/' . $following->getID(), // Callback, unique to each subscriber
                        'hub.mode' => 'unsubscribe',
                        'hub.verify' => 'async', // Backwards compatibility with v0.3 hubs
                        'hub.topic' => $following->pubsub_self
                    ]);
                    
                    error_log("Pubsub: " . print_r($return, true));
                }
            });
        }

        function registerPages() {
            // Create an endpoint for subscription pings
            \Idno\Core\site()->addPageHandler('/pubsub/callback/([A-Za-z0-9]+)/([A-Za-z0-9]+)/?', '\Idno\Pages\Pubsubhubbub\Callback');

        }

        /**
         * Find all hub urls for a given url, by looking at its feeds.
         */
        private function discoverHubs($url) {

            $hubs = [];
            
            // Get page
            $page = \Idno\Core\Webservice::file_get_contents($url);
            
            // Find the feed in page
            $feed = $this->findFeed($url, $page);
            
            // See if we have a hub link in the main page
            if (preg_match_all('/<link href=["\']{1}([^"]+)["\']{1} rel=["\']{1}hub["\']{1}[\s]*\/?>/i', $page, $match)) {
                $hubs = array_merge($match[1]);
            }
            if (preg_match_all('/<link rel=["\']{1}hub["\']{1} href=["\']{1}([^"]+)["\']{1}[\s]*\/?>/i', $page, $match)) {
                $hubs = array_merge($match[1]);
            }

            if ($feed) {
                
                $page = \Idno\Core\Webservice::file_get_contents($feed);
                
                // We may be looking on a feed
                if (preg_match_all('/<atom:link href=["\']{1}([^"]+)["\']{1} rel=["\']{1}hub["\']{1}[\s]*\/?>/i', $page, $match)) {
                    $hubs = array_merge($match[1]);
                }
                if (preg_match_all('/<atom:link rel=["\']{1}hub["\']{1} href=["\']{1}([^"]+)["\']{1}[\s]*\/?>/i', $page, $match)) {
                    $hubs = array_merge($match[1]);
                }
            }

            if (count($hubs))
                return $hubs;

            return false;
        }

        /**
         * Find the (first) feed on a given URL.
         * @param type $url
         * @return type
         */
        private function findFeed($url, $data = null) {
            $feed = null;
            
            if (!$data)
                $data = \Idno\Core\Webservice::file_get_contents($url);
            
            // serach for all 'RSS Feed' declarations 
            if (preg_match_all('#<link[^>]+type="application/rss\+xml"[^>]*>#is', $data, $rawMatches)) {
                
                if (preg_match('#href="([^"]+)"#i', $rawMatches[0][0], $rawUrl)) {
                    $feed = $rawUrl[1];
                }

            }
            
            return $feed;
        }
        
        /**
         * Find the self resource.
         * This method will find a link self on a feed, finding the feed first
         * @param type $url
         */
        private function findSelf($url) {

            $self = null;
            $feed = null;

            // Find RSS
            $feed = $this->findFeed($url);

            // Find self
            if ($feed) {
                $data = \Idno\Core\Webservice::file_get_contents($feed);
                
                if (preg_match('/<atom:link[^>]+href="([^"]+)"[^>]*rel="self"[^>]*>/i', $data, $match)) {
                    $self = $match[1];
                }
                if (preg_match('/<atom:link[^>]+rel="self"[^>]*href="([^"]+)"[^>]*>/i', $data, $match)) {
                    $self = $match[1];
                }
            }

            return $self;
        }

        /**
         * If this idno installation has a PubSubHubbub hub, send a publish notification to the hub
         * @param string $url
         * @return array
         */
        static function publish($url) {
            if ($hub = \Idno\Core\site()->config()->hub) {

                return \Idno\Core\Webservice::post($hub, [
                            'hub.mode' => 'publish',
                            'hub.url' => \Idno\Core\site()->config()->feed
                ]);
            }

            return false;
        }

    }

}