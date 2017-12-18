<?php
namespace AppBundle\Service;

use Doctrine\ORM\EntityManager;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Validator\Constraints\DateTime;
use Symfony\Component\Console\Output\OutputInterface;

use AppBundle\Command\ScraperCommand;
use AppBundle\Entity\Party;
use AppBundle\Entity\Metadata;
use AppBundle\Entity\Statistic;
use AppBundle\Entity\SocialMedia;

use Pirates\PapiInfo\Compile;

class DatabaseService
{
    private   $container;
    protected $em;
    protected $log;

    protected $meta  = [];
    protected $posts = [];
    protected $stats = [];

    public function __construct(EntityManager $entityManager, Container $container) {
        $this->container = $container;
        $this->em        = $entityManager;
        $this->log       = $this->container->get('logger');
        @set_exception_handler(array($this, 'exception_handler'));
    }


    public function exception_handler($e) {
        $this->log->error($e->getMessage());
    }


    /**
     * Queries DB for all parties
     * @return array
     */
    public function getAllParties() {
        $parties = $this->em
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
        $party = $this->em
            ->getRepository('AppBundle:Party')
            ->findOneByCode($code);

        if (empty($party)) {
            $this->log->error("   - ERROR - Party code \"". $code ."\" not recognised\n");
            $this->log->notice("# Process halted\n");
            exit;
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
        $m = $this->em
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
     * Builds or updates a SocialMedia object
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
        $p = $this->em
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
        $fbLaunch  = strtotime("04 February 2004"); // Facebook launch date
        $ytLaunch  = strtotime("14 February 2005"); // YouTube launch date
        $twLaunch  = strtotime("15 July 2006");     // Twitter launch date
        $gpLaunch  = strtotime("28 June 2011");     // Google+ launch date
        $timeLimit = strtotime("-1 year");          // our time limit

        if ($scrapeFull) { // if user requested a full scrape
            $this->log->info("      - Full scrape requested, getting all... ");
            switch ($type) {
                case 'tw':
                    $limit = $twLaunch;
                    break;
                case 'fb':
                    $limit = $fbLaunch;
                    break;
                default:
                    $limit = $ytLaunch;
            }
            return $limit;
        }

        $p = $this->em
            ->getRepository('AppBundle:SocialMedia')
            ->findOneBy([
                'code' => $partyCode,
                'type' => $type,
                'subType' => $subType
                ],[
                'postTime' => 'DESC'
                ]
            );

        if (!empty($p)) {
            $this->log->info("      + Database !empty, updating... ");
            $limit = $p->getPostTime()->getTimestamp();
            $this->log->debug("       + (Lastest databse entry: " . date('d/m/Y', $limit) . ")");
            return $limit;
        }

        $this->log->info("      - Database empty, getting all... ");
        switch ($type) {
            case 'tw':
                $limit = $timeLimit;
                break;
            case 'fb':
                $limit = ($subType == 'T') ? $timeLimit : $fbLaunch;
                break;
            default:
                $limit = $ytLaunch;
        }
        return $limit;
    }

}