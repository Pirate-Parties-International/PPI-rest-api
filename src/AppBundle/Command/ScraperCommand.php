<?php
namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use Pirates\PapiInfo\Compile;

use TwitterAPIExchange;
use Madcoda\Youtube;

use AppBundle\Entity\Party;
use AppBundle\Entity\Metadata;
use AppBundle\Entity\Statistic;

use Facebook\Facebook;
use Facebook\FacebookSDKException;
use Facebook\FacebookResponseException;


class ScraperCommand extends ContainerAwareCommand
{
    
    protected $stats = [];
    protected $meta = [];

    public $coverRoot = "";

    protected function configure()
    {
        $this
            ->setName('papi:scraper')
            ->setDescription('Scrapes FB, TW and G+ data. Should be run once per day.')
            ->addOption('party', 'p', InputOption::VALUE_OPTIONAL, 'Choose a single party to scrape, by code (i.e. ppsi)') // if null, get all
            ->addOption('data', 'd', InputOption::VALUE_OPTIONAL, 'Choose a single data point to scrape (fb, tw, g+ or yt)') // if null, get all
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $who = $input->getOption('party');
        $what = $input->getOption('data');

        $this->container = $this->getContainer();
        $this->em = $this->container->get('doctrine')->getManager();

        $appRoot = $this->container->get('kernel')->getRootDir() . '/..';
        $this->coverRoot = $appRoot . '/web/img/fb-covers/';
        
        $this->output = $output;
        $this->logger = $this->getContainer()->get('logger');
        $logger = $this->logger;

        $output->writeln("##### Starting scraper #####");

        if (empty($who)) {
            $output->writeln("# Getting all parties");
            $parties = $this->getAllParties();
            $output->writeln("Done");
        } else {
            $output->writeln("# Getting one party (". $who .")");
            $parties = $this->getOneParty($who);
            $output->writeln("Done");
        }

        foreach ($parties as $code => $party) {
            
            $output->writeln(" - Processing " . $code);
            $sn = $party->getSocialNetworks();

            if (empty($sn)) {
                continue;
            }

          if ($what == null || $what == 'fb') {
            //
            // FACEBOOK
            // 
            if (!empty($sn['facebook']) && !empty($sn['facebook']['username'])) {
                $output->writeln("     + Starting Facebook import");
                $fd = $this->getFBData($sn['facebook']['username']); 

                if ($fd == false || empty($fd['likes'])) {
                    $output->writeln("     + ERROR while retrieving FB data");
                } else {
                    $output->writeln("     + Facebook data retrieved");
                    $this->addStatistic(
                        $code,
                        Statistic::TYPE_FACEBOOK, 
                        Statistic::SUBTYPE_LIKES,
                        $fd['likes']
                    );
                    $output->writeln("     + 'Like' count added");
                    $this->addStatistic(
                        $code,
                        Statistic::TYPE_FACEBOOK,
                        Statistic::SUBTYPE_TALKING,
                        $fd['talking']
                    );
                    $output->writeln("     + 'Talking about' count added");
                    $this->addStatistic(
                        $code,
                        Statistic::TYPE_FACEBOOK, 
                        Statistic::SUBTYPE_POSTS,
                        $fd['postCount']
                    );
                    $output->writeln("     + Post count added");
                    $this->addStatistic(
                        $code,
                        Statistic::TYPE_FACEBOOK,
                        Statistic::SUBTYPE_IMAGES,
                        $fd['photoCount']
                    );
                    $output->writeln("     + Photo count added");
                    $this->addStatistic(
                        $code,
                        Statistic::TYPE_FACEBOOK,
                        Statistic::SUBTYPE_EVENTS,
                        $fd['eventCount']
                    );
                    $output->writeln("     + Event count added");
                    $output->writeln("     + All statistics added");

                    $cover = $this->getFacebookCover($code, $fd['cover']);
                    $output->writeln("     + Cover retrieved");

                    $this->addMeta(
                        $code,
                        Metadata::TYPE_FACEBOOK_COVER,
                        $cover
                    );
                    $output->writeln("     + Cover added");

                    $this->addMeta(
                        $code,
                        Metadata::TYPE_FACEBOOK_DATA,
                        json_encode($fd['data'])
                    );
                    $output->writeln("     + General data added");

                    if (!empty($fd['posts'])) {
                        $this->addMeta(
                            $code,
                            Metadata::TYPE_FACEBOOK_POSTS,
                            json_encode($fd['posts'])
                        );
                    }
                    $output->writeln("     + Posts added");
                    if (!empty($fd['photos'])) {
                        $this->addMeta(
                            $code,
                            Metadata::TYPE_FACEBOOK_PHOTOS,
                            json_encode($fd['photos'])
                        );
                    }
                    $output->writeln("     + Photos added");
                    if (!empty($fd['events'])) {
                            $this->addMeta(
                            $code,
                            Metadata::TYPE_FACEBOOK_EVENTS,
                            json_encode($fd['events'])
                        );
                    }
                    $output->writeln("     + Events added");

                    $output->writeln("     + Metadata added");
                }
            }

          }

          if ($what == null || $what == 'tw') {
            //
            // TWITTER
            // 
            if (!empty($sn['twitter']) && !empty($sn['twitter']['username'])) {
                $output->writeln("     + Starting Twitter import");
                $td = $this->getTwitterData($sn['twitter']['username']);

                if ($td == false ||
                    empty($td['followers']) ||
                    empty($td['tweets'])
                    ) {
                    $output->writeln("     + ERROR while retrieving TW data");
                } else {
                    $output->writeln("     + Twitter data retrieved");
                    $this->addStatistic(
                        $code,
                        Statistic::TYPE_TWITTER,
                        Statistic::SUBTYPE_LIKES,
                        $td['likes']
                    );
                    $this->addStatistic(
                        $code,
                        Statistic::TYPE_TWITTER,
                        Statistic::SUBTYPE_FOLLOWERS,
                        $td['followers']
                    );
                    $this->addStatistic(
                        $code,
                        Statistic::TYPE_TWITTER,
                        Statistic::SUBTYPE_FOLLOWING,
                        $td['following']
                    );
                    $this->addStatistic(
                        $code,
                        Statistic::TYPE_TWITTER,
                        Statistic::SUBTYPE_POSTS,
                        $td['tweets']
                    );
                    $output->writeln("     + Statistics added");

                    $this->addMeta(
                        $code,
                        Metadata::TYPE_TWITTER_DATA,
                        json_encode($td['description'])
                    );

                    if (!empty($td['posts'])) {
                        $this->addMeta(
                            $code,
                            Metadata::TYPE_TWITTER_POSTS,
                            json_encode($td['posts'])
                        );
                    }

                    $output->writeln("     + Metadata added");
                }
            }
          }

          if ($what == null || $what == 'g+') {
            //
            // Google+
            // 
            if (!empty($sn['googlePlus'])) {
                $output->writeln("     + Starting GooglePlus import");
                $gd = $this->getGooglePlusData($sn['googlePlus']);

                if ($gd == false ||
                    empty($gd)
                    ) {
                    $output->writeln("     + ERROR while retrieving G+ data");
                } else {
                    $output->writeln("     + GooglePlus data retrieved");
                    $this->addStatistic(
                        $code,
                        Statistic::TYPE_GOOGLEPLUS,
                        Statistic::SUBTYPE_FOLLOWERS,
                        $gd
                    );
                    $output->writeln("     + Statistic added");
                }
            }
          }

          if ($what == null || $what == 'yt') {
            //
            // Youtube
            // 
            if (!empty($sn['youtube'])) {
                $output->writeln("     + Starting Youtube import");
                $yd = $this->getYoutubeData($sn['youtube']);

                if ($yd == false ||
                    empty($yd)
                    ) {
                    $output->writeln("     + ERROR while retrieving Youtube data");
                } else {
                    $output->writeln("     + Youtube data retrieved");
                    $this->addStatistic(
                        $code,
                        Statistic::TYPE_YOUTUBE,
                        Statistic::SUBTYPE_SUBSCRIBERS,
                        $yd['stats']['subscriberCount']
                    );

                    $this->addStatistic(
                        $code,
                        Statistic::TYPE_YOUTUBE,
                        Statistic::SUBTYPE_VIEWS,
                        $yd['stats']['viewCount']
                    );

                    $this->addStatistic(
                        $code,
                        Statistic::TYPE_YOUTUBE,
                        Statistic::SUBTYPE_VIDEOS,
                        $yd['stats']['videoCount']
                    );

                    $output->writeln("     + Statistics added");

                    if (!empty($yd['videos'])) {
                        $this->addMeta(
                            $code,
                            Metadata::TYPE_YOUTUBE_VIDEOS,
                            json_encode($yd['videos'])
                        );
                    }

                    $output->writeln("     + Metadata added");
                }
            }
          }

          else if ($what != null && $what != 'yt' && $what != 'g+' && $what != 'tw' && $what != 'fb') {
            $output->writeln("     + ERROR - Data point \"". $what ."\" not recognised");
            $output->writeln("# Process halted");
            die;
          }
            
        }

        $output->writeln("# Saving to DB");
        $this->em->flush();

        $output->writeln("# Done");        
        
    }

    /**
     * Builds a Statistic object
     * @param string $type    
     * @param string $subType 
     * @param integer $value   
     *
     * @return Statistic
     */
    public function addStatistic($code, $type, $subType, $value) {
        $s = new Statistic();
        $s->setCode($code);
        $s->setType($type);
        $s->setSubType($subType);
        $s->setValue($value);
        $s->setTimestamp(new \DateTime());

        $this->em->persist($s);
        
        $this->stats[] = $s;
        return $s;
    }

    /**
     * Builds or updates a Metadata object
     * 
     * @param string $code 
     * @param string $type 
     * @param string $value
     *
     * @return Metadata
     */
    public function addMeta($code, $type, $value) {
        $m = $this->container->get('doctrine')
        ->getRepository('AppBundle:Metadata')
        ->findOneBy([
            'type' => $type,
            'code' => $code
            ]);

        if (!$m) {
            $m = new Metadata();
            $m->setCode($code);
            $m->setType($type);      
        }

        $m->setValue($value);

        $this->em->persist($m);

        $this->meta[] = $m;
        return $m;
    }

    /**
     * Retrives facebook covers and saves them to disk
     * @param  string $code 
     * @param  string $url  
     * @return string       local relative path
     */
    public function getFacebookCover($code, $url) {
        if (!is_dir($this->coverRoot)) {
            mkdir($this->coverRoot, 0755, true);
        }

        preg_match('/.+\.(png|jpg)/i', $url, $matches);
        $fileEnding = $matches[1];

        $img = file_get_contents($url);
        $filename = strtolower($code) . '.' . $fileEnding;
        $fullPath = $this->coverRoot . $filename;
        file_put_contents($fullPath, $img);

        $this->cropImage($fullPath);

        return '/img/fb-covers/' . $filename; 
    }

    /**
     * Crops the image
     * @param  string $path 
     * @return null
     */
    public function cropImage($path) {
        $crop_width = 851;
        $crop_height = 315;

        $image = new \Imagick($path);
        $geometry = $image->getImageGeometry();

        $width = $geometry['width'];
        $height = $geometry['height'];

        if ($width == $crop_width && $height == $crop_height) {
            return;
        }
        if ($width < $crop_width && $height < $crop_height) {
            return;
        }

        // First we scale
        $x_index = ($width / $crop_width);
        $y_index = ($height / $crop_height);

        if ($x_index > 1 && $y_index > 1) {
            $image->scaleImage($crop_width, 0);
        }

        $geometry = $image->getImageGeometry();
        if ($geometry['height'] > 351) {
            $image->cropImage($crop_width, $crop_height, 0, 0);
        }

        file_put_contents($path, $image);
    }

    /**
     * Queries DB for all parties
     * @return array
     */
    public function getAllParties() {
        
        $parties = $this->container->get('doctrine')
        ->getRepository('AppBundle:Party')
        ->findAll();
        
        $allData = array();
        foreach ($parties as $party) {
            $allData[strtolower($party->getCode())] = $party;
        }

        return $allData;
    }

    /**
     * Queries DB for one party
     * @return array
     */
    public function getOneParty($code) {

        $party = $this->container->get('doctrine')
            ->getRepository('AppBundle:Party')
            ->findOneByCode($code);

        if (empty($party)) {
            echo ("     + ERROR - Party code \"". $code ."\" not recognised\n");
            echo ("# Process halted\n");
            die;
        }

        $data = array();
        $data[strtolower($party->getCode())] = $party;

        return $data;
    }

    /**
     * Queries Facebook for stats, posts, images and events
     * @return array
     */
    public function getFBData($fbPageId) {
        $fb = new Facebook([
          'app_id' => $this->container->getParameter('fb_app_id'),
          'app_secret' => $this->container->getParameter('fb_app_secret'),
          'default_graph_version' => 'v2.7',
        ]);
        $fb->setDefaultAccessToken($this->container->getParameter('fb_access_token'));

        $request = $fb->request(
          'GET',
          $fbPageId,
          array(
            'fields' => 'cover,engagement,talking_about_count,about,emails,single_line_address,
                            posts.limit(25).summary(true){created_time,updated_time,message,story,link,name,caption,picture,likes.limit(0).summary(true),
                                reactions.limit(0).summary(true),comments.limit(0).summary(true),shares},
                            albums{count,photos{created_time,updated_time,picture,link,name,event,place,album,likes.limit(0).summary(true),
                                reactions.limit(0).summary(true),comments.limit(0).summary(true),sharedposts.limit(0).summary(true)}},
                            events{start_time,updated_time,name,cover,description,place,attending_count,interested_count,comments.limit(0).summary(true)}'
          )
        );

        try {
          $response = $fb->getClient()->sendRequest($request);
        } catch(Facebook\Exceptions\FacebookResponseException $e) {
          // When Graph returns an error
          echo 'Graph returned an error: ' . $e->getMessage();
          exit;
        } catch(Facebook\Exceptions\FacebookSDKException $e) {
          // When validation fails or other local issues
          echo 'Facebook SDK returned an error: ' . $e->getMessage();
          exit;
        } catch (\Exception $e) {
            echo $fbPageId . " - Exception: " . $e->getMessage();
            return false;
        }

        $graphNode = $response->getGraphNode();

        //
        // Basic page info
        //
        $out = [
            'likes'   => $graphNode->getField('engagement')->getField('count'),
            'talking' => $graphNode->getField('talking_about_count'),
        ];

        $out['data'] = [
            'about'   => $graphNode->getField('about'),
            'address' => $graphNode->getField('single_line_address')
        ];

       $fdEmails = $graphNode->getField('emails');
        if (!empty($fdEmails)) {
            foreach ($fdEmails as $key => $email) {
                $out['data']['email'] = $email;
            }
        }

        //
        // Info for posts
        //
        $fdPosts = $graphNode->getField('posts');
        if (!empty($fdPosts)) {

            foreach ($fdPosts as $key => $post) {

                $likeData     = $post->getField('likes')->getMetadata();
                $reactionData = $post->getField('reactions')->getMetadata();
                $commentData  = $post->getField('comments')->getMetadata();
                $shareData    = json_decode($post->getField('shares'), true);

                $out['posts'][] = [
                    'id'          => $post->getField('id'),
                    'posted'      => $post->getField('created_time')->format('c'),
                    'updated'     => $post->getField('updated_time')->format('c'),
                    'message'     => $post->getField('message'),
                    'story'       => $post->getField('story'),
                    'link'        => [
                      'url'         => $post->getField('link'),
                      'name'        => $post->getField('name'),
                      'caption'     => $post->getField('caption'),
                      'thumb'       => $post->getField('picture')
                    ],
                    'likes'       => $likeData['summary']['total_count'],
                    'reactions'   => $reactionData['summary']['total_count'],
                    'comments'    => $commentData['summary']['total_count'],
                    'shares'      => $shareData['count']
                ];
            }

            $out['postCount'] = count($out['posts']);
        } else {
            $out['postCount'] = 0;
        }

        //
        // Info for images
        //
        $fdAlbums = $graphNode->getField('albums');
        if (!empty($fdAlbums)) {
            foreach ($fdAlbums as $key => $album) {

              $photoCount[] = $album->getField('count');

              $fdPhotos = $album->getField('photos');
              if (!empty($fdPhotos)) {
                foreach ($fdPhotos as $key => $photo) {

                    $likeData     = $photo->getField('likes')->getMetadata();
                    $reactionData = $photo->getField('reactions')->getMetadata();
                    $commentData  = $photo->getField('comments')->getMetadata();
                    $shareData    = count(json_decode($photo->getField('sharedposts'), true));

                    $out['photos'][]    = [
                        'id'        => $photo->getField('id'),
                        'posted'    => $photo->getField('created_time')->format('c'),
                        'updated'   => $photo->getField('updated_time')->format('c'),
                        'picture'   => $photo->getField('picture'),
                        'url'       => $photo->getField('link'),
                        'details'   => [
                          'name'      => $photo->getField('name'), // caption
                          'album'     => [
                            'id'        => $photo->getField('album')->getField('id'),
                            'name'      => $photo->getField('album')->getField('name')
                          ],
                          'event'     => $photo->getField('event'),
                          'place'     => $photo->getField('place')
                        ],
                        'likes'     => $likeData['summary']['total_count'],
                        'reactions' => $reactionData['summary']['total_count'],
                        'comments'  => $commentData['summary']['total_count'],
                        'shares'    => $shareData
                    ];
                }
              }
            }

            $out['photoCount'] = array_sum($photoCount);
        } else {
            $out['photoCount'] = 0;
        }

        //
        // Info for events
        //
        $fdEvents = $graphNode->getField('events');
        if (!empty($fdEvents)) {
            foreach ($fdEvents as $key => $event) {

                $place = $event->getField('place');
                if (!empty($place)) {
                    $placeName = $place->getField('name');
                    $location  = $place->getField('location');
                } else $placeName = null;

                if (!empty($location)) {
                    $placeAddress = [
                        'street'    => $location->getField('street'),
                        'city'      => $location->getField('city'),
                        'zip'       => $location->getField('zip'),
                        'country'   => $location->getField('country'),
                        'longitude' => $location->getField('longitude'),
                        'latitude'  => $location->getField('latitude')
                    ];
                } else $placeAddress = null;

                $commentData  = $event->getField('comments')->getMetadata();
                $coverData    = json_decode($event->getField('cover'), true);

                $out['events'][] = [
                    'id'          => $event->getField('id'),
                    'start_time'  => $event->getField('start_time')->format('c'),
                    'updated'     => $event->getField('updated_time')->format('c'),
                    'name'        => $event->getField('name'),
                    'details'     => [
                      'description' => $event->getField('description'),
                      'place'       => $placeName,
                      'address'     => $placeAddress,
                      'cover'       => [
                        'id'          => $coverData['id'],
                        'source'      => $coverData['source']
                      ]
                    ],
                    'attending'   => $event->getField('attending_count'),
                    'interested'  => $event->getField('interested_count'),
                    'comments'    => $commentData['summary']['total_count']
                ];
            }
            
            $out['eventCount'] = count($out['events']);
        } else {
            $out['eventCount'] = 0;
        }

        //
        // Second step for images
        //
        $imageId =  $graphNode->getField('cover')->getField('cover_id');

        $request = $fb->request(
          'GET',
          $imageId,
          array(
            'fields' => 'height,width,album,images'
          )
        );

        try {
          $response = $fb->getClient()->sendRequest($request);
        } catch(Facebook\Exceptions\FacebookResponseException $e) {
          // When Graph returns an error
          echo 'Graph returned an error: ' . $e->getMessage();
          exit;
        } catch(Facebook\Exceptions\FacebookSDKException $e) {
          // When validation fails or other local issues
          echo 'Facebook SDK returned an error: ' . $e->getMessage();
          exit;
        } catch (\Exception $e) {
            echo $fbPageId . " - Exception: " . $e->getMessage();
            return false;
        }

        $graphNode = $response->getGraphNode();

        $images = $graphNode->getField('images');

        $tmpI = [];
        $tmpA = [];
        foreach ($images as $key => $img) {
            if ($img->getField('width') == 851 && $img->getField('height') == 351) {
                $out['cover'] = $img->getField('source');
                return $out;
            } else if ($img->getField('width') > 851 && $img->getField('height') > 351) {
                $tmpI[$img->getField('width') + $img->getField('height')] = $img->getField('source');
            } else {
                $tmpA[$img->getField('width') + $img->getField('height')] = $img->getField('source');
            }
        }

        if (!empty($tmpI)) {
            $t = max(array_keys($tmpI));
            $img = $tmpI[$t];
        } else {
            $t = max(array_keys($tmpA));
            $img = $tmpA[$t];
        }

        $out['cover'] = $img;

        return $out;
    }

    /**
     * Queries Twitter for stats and tweets
     * @return array
     */
    public function getTwitterData($username) {
        $settings = array(
            'oauth_access_token'        => $this->container->getParameter('tw_oauth_access_token'),
            'oauth_access_token_secret' => $this->container->getParameter('tw_oauth_access_token_secret'),
            'consumer_key'              => $this->container->getParameter('tw_consumer_key'),
            'consumer_secret'           => $this->container->getParameter('tw_consumer_secret')
        );

        $url = 'https://api.twitter.com/1.1/users/show.json';
        $getfield = '?screen_name='.str_replace("@", "", $username);
        $requestMethod = 'GET';

        $twitter = new TwitterAPIExchange($settings);
        $data = $twitter->setGetfield($getfield)
            ->buildOauth($url, $requestMethod)
            ->performRequest();

        if (empty($data)) {
            return false;
        }

        $data = json_decode($data);

        if (empty($data->followers_count)) {
            return false;
        }

        $out = [
            'description' => $data->description,
            'tweets'      => $data->statuses_count,
            'likes'       => $data->favourites_count,
            'followers'   => $data->followers_count,
            'following'   => $data->friends_count,
        ];

        $tweetUrl = 'https://api.twitter.com/1.1/statuses/user_timeline.json';
        $tweetData = $twitter->setGetField($getfield)
                ->buildOauth($tweetUrl, $requestMethod)
                ->performRequest();

        if (empty($tweetData)) {
            return false;
        }

        $tweetData = json_decode($tweetData);

        if (!empty($tweetData)) {
            foreach($tweetData as $item) {
                $out['posts'][] = [
                    'id'       => $item->id,
                    'time'     => $item->created_at,
                    'text'     => $item->text,
                    'likes'    => $item->favorite_count,
                    'retweets' => $item->retweet_count
                ];
            }
        }

        return $out;
    }

    /**
     * Queries Google+ for followers
     * @return int
     */
    public function getGooglePlusData($id) {
        $apikey = $this->container->getParameter('gplus_api_key');
        $google = $this->curl(
            sprintf('https://www.googleapis.com/plus/v1/people/%s?key=%s',
                $id, $apikey)
            );
        $data = json_decode( $google );
        if (empty($data) || !isset($data->circledByCount)) {
            return false;
        }
        return $data->circledByCount;

    }

    /**
     * Queries Youtube for stats and videos
     * @return array
     */
    public function getYoutubeData($id) {
        $apikey = $this->container->getParameter('gplus_api_key');
        $youtube = new Youtube(array('key' => $apikey));

        $data = $youtube->getChannelByName($id);

        if (empty($data)) {
            return false;
        }
        if (empty($data->statistics) || empty($data->statistics->viewCount)) {
            return false;
        }
        $out['stats']['viewCount']       = $data->statistics->viewCount;
        $out['stats']['subscriberCount'] = $data->statistics->subscriberCount;
        $out['stats']['videoCount']      = $data->statistics->videoCount;

        $playlist = $data->contentDetails->relatedPlaylists->uploads;

        $videos = $youtube->getPlaylistItemsByPlaylistId($playlist);

        if (!empty($videos)) {
            $out['videos'] = [];

            foreach ($videos as $key => $vid) {

                $vidId   = $vid->snippet->resourceId->videoId;
                $vidInfo = $youtube->getVideoInfo($vidId);

                if (!empty($vidInfo->statistics->likeCount)) {
                    $vidLikes = $vidInfo->statistics->likeCount;
                } else {
                    $vidLikes = "0";
                }

                if (!empty($vidInfo->statistics->commentCount)) {
                    $vidComments = $vidInfo->statistics->commentCount;
                } else {
                    $vidComments = "0";
                }

                $out['videos'][] = [
                    'id'       => $vidId,
                    'date'     => $vid->snippet->publishedAt,
                    'title'    => $vid->snippet->title,
                    'tumb'     => $vid->snippet->thumbnails->medium->url,
                    'views'    => $vidInfo->statistics->viewCount,
                    'likes'    => $vidLikes,
                    'comments' => $vidComments
                ];
            }
        }

        return $out;

    }


    public function curl($url) {
        // Get cURL resource
        $curl = curl_init();
        // Set some options - we are passing in a useragent too here
        curl_setopt_array($curl, array(
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_URL => $url,
            CURLOPT_USERAGENT => 'PAPI'
        ));
        // Send the request & save response to $resp
        $resp = curl_exec($curl);
        // Close request to clear up some resources
        curl_close($curl);

        return $resp;
    }
}