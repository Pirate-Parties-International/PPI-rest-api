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
        echo $e->getMessage()."\n";
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

        $postText = (strlen($postText)>191) ? $this->getTruncatedString($postText) : $postText;

        if (strlen($postText)>191) {
            echo "post ".$postId." text too long (".strlen($postText)." characters)\n";
            die;
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
     * Truncates a string to 191 characters or fewer to fit varchar field
     * @param  string $string
     * @return string
     **/
    public function getTruncatedString($string) {
        $string = trim($string);

        if (strlen($string) > 191) { // if string is longer than 191 characters
            $string = wordwrap($string, 185, "<cut>"); // split into substrings
            $string = explode("<cut>", $string, 2); // add substrings to array
            $string = trim($string[0]).' […]'; // save only first substring and add ellipses

            if (strlen($string) > 191) { // double check in case wordwrap failed
                $string = substr($string, 0, 185).' […]'; // force if necessary
            }
        }

        return $string;
    }


    /**
     * Queries DB for a party's latest social media entry of a specified type
     * @param  string $type
     * @param  string $subType
     * @param  string $code
     * @param  bool   $full
     * @return int
     */
    public function getTimeLimit($type, $subType, $code, $full = false) {

        $limited   = strtotime("-1 year"); // set age limit for fb text posts and tweets
        $unlimited = strtotime("-20 years"); // practically no limit, get all

        if ($full) { // if user requested a full scrape
            echo "getting all... ";
            switch ($type) {
                case 'tw':
                    $time = $limited; // age limit for all tweets
                    break;
                case 'fb':
                    $time = ($subType == 'T') ? $limited : $unlimited; // limit for text posts only
                    break;
                default:
                    $time = $unlimited; // no limit for yt videos, get all
            }

        } else {
            echo "checking database...";
            $p = $this->container->get('doctrine')
                ->getRepository('AppBundle:SocialMedia')
                ->findOneBy(['code' => $code, 'type' => $type, 'subType' => $subType],['postTime' => 'DESC']);

            if (empty($p)) { // if there are no entries in the database, populate fully
                echo " empty, getting all... ";
                switch ($type) {
                    case 'tw':
                        $time = $limited; // age limit for all tweets
                        break;
                    case 'fb':
                        $time = ($subType == 'T') ? $limited : $unlimited; // limit for text posts only
                        break;
                    default:
                        $time = $unlimited; // no limit for yt videos, get all
                }

            } else { // if there are entries already in the db, only get updates since the latest one
                echo " !empty, updating... ";
                $time = $p->getPostTime()->getTimestamp();
            }
        }

        return $time;
    }


    /**
     * Saves uploaded images to disk
     * @param  string $site
     * @param  string $code
     * @param  string $imgSrc
     * @param  string $imgId
     * @param  string $imgBkp
     * @return string
     */
    public function saveImage($site, $code, $imgSrc, $imgId, $imgBkp = null) {

        $appRoot = $this->container->get('kernel')->getRootDir().'/..';
        $imgRoot = $appRoot.'/web/img/uploads/'.$code.'/'.$site.'/';
        preg_match('/.+\.(png|jpg)/i', $imgSrc, $matches);

        if (empty($matches)) {
            return null;
        }

        $imgFmt  = $matches[1];
        $imgName = $imgId.'.'.$imgFmt;
        $imgPath = $imgRoot.$imgName;

        if (!is_dir($imgRoot)) { // check if directory exists, else create
            mkdir($imgRoot, 0755, true);
        }

        $ctx = stream_context_create(array(
            'http' => array(
                'timeout' => 15
                )
            )
        );

        if (!file_exists($imgPath)) { // check if file exists on disk before saving
            try {
                $imgData = file_get_contents($imgSrc, false, $ctx);
            } catch (\Exception $e) {
                echo $e->getMessage();
                $out['errors'][] = [$code => $imgPath];
                if ($imgBkp) { // try backup if available
                    echo " trying backup... ";
                    try {
                        $imgData = file_get_contents($imgBkp, false, $ctx);
                        echo "successful";
                    } catch (\Exception $e) {
                        echo "unsuccessful";
                    }
                    echo ", ";
                }
            }
        }

        if (!empty($imgData)) {
            try {
                file_put_contents($imgPath, $imgData);
            } catch (\Exception $e) {
                echo $e->getMessage();
                $out['errors'][] = [$code => $imgPath];
            }
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

        $connected = false;
        $tryCount  = 0;
        do {
            try {
                // Send the request & save response to $resp
                $resp = curl_exec($curl);
                $connected = true;
            } catch (\Exception $e) {
                echo $e->getMessage()."\n";
                $out['errors'][] = [$code => $e->getMessage()];
                $tryCount++;
                return false;
            }
        } while ($connected == false && $tryCount < 5);

        // Close request to clear up some resources
        curl_close($curl);

        return $resp;
    }


}