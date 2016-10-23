<?php
namespace AppBundle\Extensions;

use Doctrine\ORM\EntityManager;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Validator\Constraints\DateTime;

use AppBundle\Command\ScraperCommand;
use AppBundle\Entity\Party;
use AppBundle\Entity\Metadata;
use AppBundle\Entity\Statistic;
use AppBundle\Entity\SocialMedia;

use Pirates\PapiInfo\Compile;

class ScraperServices
{
    protected $stats = [];
    protected $meta  = [];
    protected $posts = [];
    protected $em;
    private   $container;


    public function exception_handler($e) {
        echo $e->getMessage();
        $out['errors'][] = ["Exception" => $e->getMessage()];
    }


    public function __construct(EntityManager $entityManager, Container $container) {
        $this->em = $entityManager;
        $this->container = $container;
        @set_exception_handler(array($this, 'exception_handler'));
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
     * @param  string $code
     * @return array
     */
    public function getOneParty($code) {
        $party = $this->container->get('doctrine')
            ->getRepository('AppBundle:Party')
            ->findOneByCode($code);

        if (empty($party)) {
            echo ("   + ERROR - Party code \"". $code ."\" not recognised\n");
            echo ("# Process halted\n");
            die;
        }

        $data = array(); // scraper is set up to work with arrays
        $data[strtolower($party->getCode())] = $party;

        return $data;
    }


    /**
     * Builds a Statistic object
     * @param  string $type
     * @param  string $subType
     * @param  int    $value
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
     * @param  string $code
     * @param  string $type
     * @param  string $value
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
     * Builds or updates a social media object
     * @param  string   $type
     * @param  string   $subType
     * @param  string   $postId
     * @param  dateTime $postTime
     * @param  string   $postText
     * @param  string   $postImage
     * @param  int      $postLikes
     * @param  array    $postData
     * @return SocialMedia
     */
    public function addSocial($code, $type, $subType, $postId, $postTime, $postText, $postImage, $postLikes, $postData) {
        $p = $this->container->get('doctrine')
            ->getRepository('AppBundle:SocialMedia')
            ->findOneByPostId($postId);

        if (!$p) {
            $p = new SocialMedia();
        }

        $p->setCode($code);
        $p->setType($type);
        $p->setSubType($subType);
        $p->setPostId($postId);
        $p->setPostTime($postTime);
        $p->setPostText($postText);
        $p->setPostImage($postImage);
        $p->setPostLikes($postLikes);
        $p->setPostData($postData);
        $p->setTimestamp(new \DateTime());

        $this->em->persist($p);

        $this->posts[] = $p;
        return $p;
    }


    /**
     * Queries DB for a party's latest social media entry of a specified type and subtype
     * @param  string $type
     * @param  string $subType
     * @param  string $code
     * @param  string $what
     * @return int
     */
    public function getTimeLimit($type, $code, $what) {

        $limited   = strtotime("-1 year"); // set age limit for fb text posts and tweets
        $unlimited = strtotime("-20 years"); // practically no limit, get all

        if ($what == 'info' || $what == 'stats') { // if only getting stats, not full data
            $time = $unlimited;
        } else {

            echo "checking database...";

            $p = $this->container->get('doctrine')
                ->getRepository('AppBundle:Statistic')
                ->findOneBy(['code' => strtolower($code), 'type' => $type],['timestamp' => 'DESC']);

            if (empty($p)) { // if there are no entries in the database, populate fully
                if ($type == 'fb' || $type == 'tw') {
                    $time = $limited; // age limit for tweets and fb posts
                } else {
                    $time = $unlimited; // no limit for yt videos, get all
                }
                echo " empty, getting all... ";

            } else { // if there are entries already in the db, only get updates since the latest one
                echo " !empty, updating... ";
                $time = $p->getTimestamp();
            }
        }

        return $time->getTimestamp();
    }


    /**
     * Saves uploaded images to disk
     * @param  string $site
     * @param  string $code
     * @param  string $imgSrc
     * @param  string $imgId
     * @return string
     */
    public function saveImage($site, $code, $imgSrc, $imgId) {

        $appRoot = $this->container->get('kernel')->getRootDir().'/..';
        $imgRoot = $appRoot.'/web/img/'.$site.'-uploads/';

        preg_match('/.+\.(png|jpg)/i', $imgSrc, $matches);
        $imgFmt  = $matches[1];
        $imgName = $imgId.'.'.$imgFmt;
        $imgPath = $imgRoot.$code.'/'.$imgName;

        if (!is_dir($imgRoot.$code.'/')) { // check if directory exists, else create
            mkdir($imgRoot.$code.'/', 0755, true);
        }

        $ctx = stream_context_create(array(
            'http' => array(
                'timeout' => 15
                )
            )
        );

        if (!file_exists($imgPath)) { // check if file exists on disk before saving
            try {
                $imgData = file_get_contents($imgSrc, false, $ctx); // try to save full source
            } catch (\Exception $e) {
                echo $e->getMessage();
                $out['errors'][] = [$code => $imgPath];
            }
        }

        if (empty($imgData)) {
            if (!empty($imgBkp)) {
                try {
                    $imgData = file_get_contents($imgBkp, false, $ctx); // try to save thumbnail instead
                } catch (\Exception $e) {
                    echo $e->getMessage();
                    $out['errors'][] = [$code => $imgPath];
                }
            }
        } else {
            file_put_contents($imgPath, $imgData);
        }

        return $imgName;

    }


    public function curl($url) {
        // Get cURL resource
        $curl = curl_init();
        // Set some options - we are passing in a useragent too here
        curl_setopt_array($curl, array(
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_URL => $url,
            CURLOPT_USERAGENT => 'PAPI'
        ));

        $tryCount = 0;
        do {
            try {
                // Send the request & save response to $resp
                $resp = curl_exec($curl);
                $tryCount++;
            } catch (\Exception $e) {
                echo $e->getMessage();
                $out['errors'][] = [$code => $e->getMessage()];
                return $out;
            }
        } while ($tryCount < 5);

        // Close request to clear up some resources
        curl_close($curl);

        return $resp;
    }


}