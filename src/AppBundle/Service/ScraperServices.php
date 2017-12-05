<?php
namespace AppBundle\Service;

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
        $output->writeln($e->getMessage());
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
            echo ("   - ERROR - Party code \"". $code ."\" not recognised\n");
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
     * Queries DB for a party's latest social media entry of a specified type
     * @param  string $type
     * @param  string $subType
     * @param  string $partyCode
     * @param  bool   $scrapeFull
     * @return int
     */
    public function getTimeLimit($type, $subType, $partyCode, $scrapeFull = false) {

        $limited   = strtotime("-1 year"); // set age limit for fb text posts and tweets
        $unlimited = strtotime("-20 years"); // practically no limit, get all

        if ($scrapeFull) { // if user requested a full scrape
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

            return $time;
        }

        echo "checking database...";
        $p = $this->container->get('doctrine')
            ->getRepository('AppBundle:SocialMedia')
            ->findOneBy([
                'code' => $partyCode,
                'type' => $type,
                'subType' => $subType
                ],['postTime' => 'DESC']
            );

        if (!empty($p)) {
            echo " !empty, updating... ";
            $time = $p->getPostTime()->getTimestamp();
            return $time;
        }

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

        return $time;
    }

}