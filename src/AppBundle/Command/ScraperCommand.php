<?php
namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use AppBundle\Entity\Metadata;
use AppBundle\Entity\Statistic;
use AppBundle\Entity\SocialMedia;

use Pirates\PapiInfo\Compile;

class ScraperCommand extends ContainerAwareCommand
{
    private   $container;
    protected $output;
    protected $db;
    protected $em;

    protected $scrapeParty = null;
    protected $scrapeSite  = null;
    protected $scrapeData  = null;
    protected $scrapeStart = null;
    protected $scrapeFull  = false;


    protected function configure()
    {
        $this
            ->setName('papi:scraper')
            ->setDescription('Scrapes FB, TW and G+ data. Should be run once per day.')
            ->addOption('party',  'p', InputOption::VALUE_OPTIONAL, 'Choose a single party to scrape, by code (i.e. ppse, ppsi)')
            ->addOption('site',   'w', InputOption::VALUE_OPTIONAL, 'Choose a single website to scrape (fb, tw, g+ or yt)')
            ->addOption('data',   'd', InputOption::VALUE_OPTIONAL, 'Choose a single data type to scrape, fb only (info, posts, images or events)')
            ->addOption('resume', 'r', InputOption::VALUE_OPTIONAL, 'Choose a point to resume scraping, by party code (e.g. if previously interrupted)')
            ->addOption('full',   'f', InputOption::VALUE_NONE,     'Scrape all data, overwriting db (by default, only posts more recent than the latest db entry are scraped)')
        ;
    }


    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->output    = $output;
        $this->container = $this->getContainer();
        $this->em        = $this->container->get('doctrine')->getManager();
        $this->db        = $this->container->get('DatabaseService');
        $this->logger    = $this->container->get('logger');
        $logger          = $this->logger;

        $output->writeln("##### Starting scraper #####");
        $startTime = new \DateTime('now');

        $parties = $this->verifyInput($input);

        foreach ($parties as $partyCode => $party) {
            if ($this->scrapeStart && ($partyCode < $this->scrapeStart)) {
                $output->writeln(" - " . $partyCode . " skipped...");

            } else {
                $output->writeln(" + Processing " . $partyCode);

                $socialNetworks = $party->getSocialNetworks();
                if (empty($socialNetworks)) {
                    $output->writeln("   - Social Network information missing");
                    continue;
                }

                $this->processParty($partyCode, $socialNetworks);
            }
        }

        $endDiff = $startTime->diff(new \DateTime('now'));
        $output->writeln("# All done in " . $endDiff->format('%H:%I:%S'));
    }


    /**
     * Verifies the input arguments and returns an array of the relevant party objects
     * @param  InputInterface
     * @return array
     */
    public function verifyInput($input) {
        $this->scrapeParty = $input->getOption('party');  // if null, get all
        $this->scrapeSite  = $input->getOption('site');   // if null, get all
        $this->scrapeData  = $input->getOption('data');   // if null, get all
        $this->scrapeStart = $input->getOption('resume'); // if null, get all
        $this->scrapeFull  = $input->getOption('full');   // if null, get most recent posts only

        if ($this->scrapeFull) {
            $this->output->writeln("### Full scrape requested, will overwrite database!");
        }

        switch ($this->scrapeSite) {
            case null:
                $siteName = "all sites";
                break;
            case 'fb':
                $siteName = "Facebook";
                break;
            case 'tw':
                $siteName = "Twitter";
                break;
            case 'g+':
                $siteName = "Google+";
                break;
            case 'yt':
                $siteName = "YouTube";
                break;
            default:
                $this->output->writeln("   - ERROR: Search term \"" . $this->scrapeSite . "\" not recognised");
                $this->output->writeln("# Process halted");
                exit;
        }

        if ($this->scrapeData) {
            switch ($this->scrapeSite) {
                case 'fb':
                case null:
                    break;
                default:
                    $this->output->writeln("   - ERROR: Search term \"" . $this->scrapeData . "\" is only valid for Facebook");
                    $this->output->writeln("# Process halted");
                    exit;
            }

            switch ($this->scrapeData) {
                case 'info':
                case 'data':
                case 'basic':
                case 'stats':
                    $this->scrapeData = 'info';
                    $dataName = "basic information";
                    break;
                case 'posts':
                case 'text':
                case 'statuses':
                    $this->scrapeData = 'posts';
                    $dataName = "text posts and videos";
                    break;
                case 'photos':
                case 'images':
                case 'pictures':
                    $this->scrapeData = 'images';
                    $dataName = "images";
                    break;
                case 'events':
                    $this->scrapeData = 'events';
                    $dataName = "events";
                    break;
                case 'videos':
                    $this->output->writeln("   - ERROR: Videos are included with text posts and can not be scraped separately");
                    $this->output->writeln("# Process halted");
                    exit;
                default:
                    $this->output->writeln("   - ERROR: Search term \"" . $this->scrapeData . "\" is not valid");
                    $this->output->writeln("# Process halted");
                    exit;
            }

            $this->scrapeSite = 'fb';
            $this->output->writeln("### Scraping Facebook for " . $dataName . " only");
        } else {
            $this->output->writeln("### Scraping " . $siteName . " for all data");
        }

        if (!$this->scrapeParty) {
            $this->output->write("# Getting all parties...");
            $parties = $this->db->getAllParties();
        } else {
            $this->output->write("# Getting one party (" . $this->scrapeParty . ")...");
            $parties = $this->db->getOneParty($this->scrapeParty);
        }
        $this->output->write(" Done\n");

        return $parties;
    }


    /**
     * Process each party according to input arguments
     * @param string $partyCode
     * @param array  $socialNetworks
     */
    public function processParty($partyCode, $socialNetworks) {
        $midTime = new \DateTime('now');

        switch ($this->scrapeSite) {
            case 'fb':
                $this->scrapeFacebook($partyCode, $socialNetworks);
                break;
            case 'tw':
                $this->scrapeTwitter($partyCode, $socialNetworks);
                break;
            case 'g+':
                $this->scrapeGooglePlus($partyCode, $socialNetworks);
                break;
            case 'yt':
                $this->scrapeYoutube($partyCode, $socialNetworks);
                break;
            default: // case null, scrape all
                $this->scrapeFacebook($partyCode, $socialNetworks);
                $this->scrapeTwitter($partyCode, $socialNetworks);
                $this->scrapeGooglePlus($partyCode, $socialNetworks);
                $this->scrapeYoutube($partyCode, $socialNetworks);
        }

        $this->output->writeln(" # Saving to DB");
        $this->em->flush();

        $midDiff = $midTime->diff(new \DateTime('now'));
        $this->output->writeln("   + Done with " . $partyCode . " in " . $midDiff->format('%H:%I:%S'));
    }


    /**
    * FACEBOOK
    * @param string $partyCode
    * @param array  $socialNetworks
    */
    public function scrapeFacebook($partyCode, $socialNetworks)
    {
        if (empty($socialNetworks['facebook']) || empty($socialNetworks['facebook']['username'])) {
            $this->output->writeln("   - Facebook data not found");
            return;
        }

        $this->output->writeln("   + Starting Facebook import");
        $fbData = $this->container
            ->get('FacebookService')
            ->getFBData($socialNetworks['facebook']['username'], $partyCode, $this->scrapeFull, $this->scrapeData);

        if ($this->scrapeData == null || $this->scrapeData == 'info') {
            if ($fbData == false || empty($fbData['likes'])) {
                $this->output->writeln("     - ERROR while retrieving FB data");
                return;
            }

            $this->output->writeln("   + Facebook data retrieved");

            $this->db->addMeta(
                $partyCode,
                Metadata::TYPE_FACEBOOK_INFO,
                json_encode($fbData['info'])
            );
            $this->output->writeln("     + General info added");

            $this->db->addStatistic(
                $partyCode,
                Statistic::TYPE_FACEBOOK,
                Statistic::SUBTYPE_LIKES,
                $fbData['likes']
            );
            $this->output->writeln("     + 'Like' count added");

            $this->db->addStatistic(
                $partyCode,
                Statistic::TYPE_FACEBOOK,
                Statistic::SUBTYPE_TALKING,
                $fbData['talking']
            );
            $this->output->writeln("     + 'Talking about' count added");

            $this->db->addStatistic(
                $partyCode,
                Statistic::TYPE_FACEBOOK,
                Statistic::SUBTYPE_POSTS,
                $fbData['postCount']
            );
            $this->output->writeln("     + Post count added");

            $this->db->addStatistic(
                $partyCode,
                Statistic::TYPE_FACEBOOK,
                Statistic::SUBTYPE_IMAGES,
                $fbData['imageCount']
            );
            $this->output->writeln("     + Image count added");

            $this->db->addStatistic(
                $partyCode,
                Statistic::TYPE_FACEBOOK,
                Statistic::SUBTYPE_VIDEOS,
                $fbData['videoCount']
            );
            $this->output->writeln("     + Video count added");

            $this->db->addStatistic(
                $partyCode,
                Statistic::TYPE_FACEBOOK,
                Statistic::SUBTYPE_EVENTS,
                $fbData['eventCount']
            );
            $this->output->writeln("     + Event count added");
            $this->output->writeln("   + All statistics added");

            if (is_null($fbData['cover'])) {
                $this->output->writeln("     - No cover found");
            } else {
                $cover = $this->container
                    ->get('ImageService')
                    ->getFacebookCover($partyCode, $fbData['cover']);
                $this->output->writeln("     + Cover retrieved");

                $this->db->addMeta(
                    $partyCode,
                    Metadata::TYPE_FACEBOOK_COVER,
                    $cover
                );
                $this->output->writeln("       + Cover added");
            }
        }

        if ($this->scrapeData == null || $this->scrapeData == 'posts') {
            if (!empty($fbData['posts'])) {
                $this->output->writeln("       + Text posts added");
            } else {
                $this->output->writeln("     - No text posts found");
            }

            if (!empty($fbData['videos'])) {
                $this->output->writeln("       + Videos added");
            } else {
                $this->output->writeln("     - No videos found");
            }
        }

        if ($this->scrapeData == null || $this->scrapeData == 'images') {
            if (!empty($fbData['images'])) {
                $this->output->writeln("       + Images added");
            } else {
                $this->output->writeln("     - No images found");
            }
        }

        if ($this->scrapeData == null || $this->scrapeData == 'events') {
            if (!empty($fbData['events'])) {
                $this->output->writeln("     + Events added");
            } else {
                $this->output->writeln("   - No events found");
            }
        }

        $this->output->writeln("   + All Facebook data added");
    }



    /**
     * TWITTER
     * @param string $partyCode
     * @param array  $socialNetworks
     */
    public function scrapeTwitter($partyCode, $socialNetworks)
    {
        if (!empty($socialNetworks['twitter']) && !empty($socialNetworks['twitter']['username'])) {
            $this->output->writeln("   + Starting Twitter import");
            $twData = $this->container
                ->get('TwitterService')
                ->getTwitterData($socialNetworks['twitter']['username'], $partyCode, $this->scrapeFull);

            if ($twData == false || empty($twData['followers']) || empty($twData['tweets'])) {
                $this->output->writeln("     - ERROR while retrieving TW data");
            } else {
                $this->output->writeln("   + Twitter data retrieved");

                $this->db->addMeta(
                    $partyCode,
                    Metadata::TYPE_TWITTER_INFO,
                    json_encode($twData['description'])
                );
                $this->output->writeln("     + General info added");

                $this->db->addStatistic(
                    $partyCode,
                    Statistic::TYPE_TWITTER,
                    Statistic::SUBTYPE_LIKES,
                    $twData['likes']
                );
                $this->output->writeln("     + 'Like' count added");

                $this->db->addStatistic(
                    $partyCode,
                    Statistic::TYPE_TWITTER,
                    Statistic::SUBTYPE_FOLLOWERS,
                    $twData['followers']
                );
                $this->output->writeln("     + Follower count added");

                $this->db->addStatistic(
                    $partyCode,
                    Statistic::TYPE_TWITTER,
                    Statistic::SUBTYPE_FOLLOWING,
                    $twData['following']
                );
                $this->output->writeln("     + Following count added");

                $this->db->addStatistic(
                    $partyCode,
                    Statistic::TYPE_TWITTER,
                    Statistic::SUBTYPE_POSTS,
                    $twData['tweets']
                );
                $this->output->writeln("     + Tweet count added");
                $this->output->writeln("   + All statistics added");

                if (!empty($twData['posts'])) {
                    $this->output->writeln("       + Text tweets added");
                } else {
                    $this->output->writeln("     - No text tweets found");
                }

                if (!empty($twData['images'])) {
                    $this->output->writeln("       + Images added");
                } else {
                    $this->output->writeln("     - No images found");
                }

                if (!empty($twData['videos'])) {
                    $this->output->writeln("       + Videos added");
                } else {
                    $this->output->writeln("     - No videos found");
                }

                $this->output->writeln("   + All Twitter data added");
            }
        }
    }


    /**
     * GOOGLE PLUS
     * @param string $partyCode
     * @param array  $socialNetworks
     */
    public function scrapeGooglePlus($partyCode, $socialNetworks)
    {
        if (!empty($socialNetworks['googlePlus'])) {
            $this->output->writeln("   + Starting GooglePlus import");
            $gData = $this->container
                ->get('GoogleService')
                ->getGooglePlusData($socialNetworks['googlePlus']);

            if ($gData == false || empty($gData)) {
                $this->output->writeln("     - ERROR while retrieving G+ data");
            } else {
                $this->output->writeln("     + GooglePlus data retrieved");

                $this->db->addStatistic(
                    $partyCode,
                    Statistic::TYPE_GOOGLEPLUS,
                    Statistic::SUBTYPE_FOLLOWERS,
                    $gData
                );
                $this->output->writeln("     + Follower count added");
            }
        }

    }


    /**
     * YOUTUBE
     * @param string $partyCode
     * @param array  $socialNetworks
     */
    public function scrapeYoutube($partyCode, $socialNetworks)
    {
        if (!empty($socialNetworks['youtube'])) {
            $this->output->writeln("   + Starting Youtube import");
            $ytData = $this->container
                ->get('GoogleService')
                ->getYoutubeData($socialNetworks['youtube'], $partyCode);

            if ($ytData == false || empty($ytData)) {
                $this->output->writeln("     - ERROR while retrieving Youtube data");
            } else {
                $this->output->writeln("   + Youtube data retrieved");

                $this->db->addStatistic(
                    $partyCode,
                    Statistic::TYPE_YOUTUBE,
                    Statistic::SUBTYPE_SUBSCRIBERS,
                    $ytData['stats']['subscriberCount']
                );
                $this->output->writeln("     + Subscriber count added");

                $this->db->addStatistic(
                    $partyCode,
                    Statistic::TYPE_YOUTUBE,
                    Statistic::SUBTYPE_VIEWS,
                    $ytData['stats']['viewCount']
                );
                $this->output->writeln("     + View count added");

                $this->db->addStatistic(
                    $partyCode,
                    Statistic::TYPE_YOUTUBE,
                    Statistic::SUBTYPE_VIDEOS,
                    $ytData['stats']['videoCount']
                );
                $this->output->writeln("     + Video count added");
                $this->output->writeln("   + All statistics added");

                if (!empty($ytData['videos'])) {
                    $this->output->writeln("       + Videos added");
                } else {
                    $this->output->writeln("     - No videos found");
                }

                $this->output->writeln("   + All Google data added");
            }
        }
    }

}
