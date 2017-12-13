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
    protected $db;
    protected $em;
    protected $log;
    protected $output;

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
        $this->log       = $this->container->get('logger');

        $this->log->notice("##### Starting scraper #####");
        $startTime = new \DateTime('now');

        $parties = $this->verifyInput($input);

        foreach ($parties as $partyCode => $party) {
            if ($this->scrapeStart && ($partyCode < $this->scrapeStart)) {
                $this->log->info("- " . $partyCode . " skipped...");

            } else {
                $this->log->info("# Processing " . $partyCode);

                $socialNetworks = $party->getSocialNetworks();
                if (empty($socialNetworks)) {
                    $this->log->warning("- Social Network information missing for " . $partyCode);
                    continue;
                }

                $this->processParty($partyCode, $socialNetworks);
            }
        }

        $endDiff = $startTime->diff(new \DateTime('now'));
        $this->log->notice("# All done in " . $endDiff->format('%H:%I:%S'));
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
            $this->log->notice("### Full scrape requested, will overwrite database!");
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
                $this->log->error("- ERROR: Search term \"" . $this->scrapeSite . "\" not recognised");
                $this->log->notice("# Process halted");
                exit;
        }

        if ($this->scrapeData) {
            switch ($this->scrapeSite) {
                case 'fb':
                case null:
                    break;
                default:
                    $this->log->error("- ERROR: Search term \"" . $this->scrapeData . "\" is only valid for Facebook");
                    $this->log->notice("# Process halted");
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
                    $this->log->error("- ERROR: Videos are included with text posts and can not be scraped separately");
                    $this->log->notice("# Process halted");
                    exit;
                default:
                    $this->log->error("- ERROR: Search term \"" . $this->scrapeData . "\" is not valid");
                    $this->log->notice("# Process halted");
                    exit;
            }

            $this->scrapeSite = 'fb';
            $this->log->info("### Scraping Facebook for " . $dataName . " only");
        } else {
            $this->log->info("### Scraping " . $siteName . " for all data");
        }

        if (!$this->scrapeParty) {
            $this->log->info("# Getting all parties...");
            $parties = $this->db->getAllParties();
        } else {
            $this->log->info("# Getting one party (" . $this->scrapeParty . ")...");
            $parties = $this->db->getOneParty($this->scrapeParty);
        }
        $this->log->info("  + Done");

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

        $this->log->info("  + Saving to DB");
        $this->em->flush();

        $midDiff = $midTime->diff(new \DateTime('now'));
        $this->log->notice("# Done with " . $partyCode . " in " . $midDiff->format('%H:%I:%S'));
    }


    /**
    * FACEBOOK
    * @param string $partyCode
    * @param array  $socialNetworks
    */
    public function scrapeFacebook($partyCode, $socialNetworks)
    {
        if (empty($socialNetworks['facebook']) || empty($socialNetworks['facebook']['username'])) {
            $this->log->warning(" - Facebook data not found for " . $partyCode);
            return false;
        }

        $this->log->info("  + Starting Facebook import");
        $fbData = $this->container->get('FacebookService')
            ->getFBData($partyCode, $socialNetworks['facebook']['username'], $this->scrapeFull, $this->scrapeData);

        if (!$fbData) {
            $this->log->notice("  - ERROR while retrieving FB data for " . $partyCode);
            return false;
        }

        $this->log->info("    + Facebook data retrieved");

        if ($this->scrapeData == null || $this->scrapeData == 'info') {
            $status = isset($fbData['info'])    ? "    + General info added"          : "    - General info not found";
            $this->log->info($status);
            $status = isset($fbData['likes'])   ? "    + 'Like' count added"          : "    - 'Like' count not found";
            $this->log->info($status);
            $status = isset($fbData['talking']) ? "    + 'Talking about' count added" : "    - 'Talking about' count not found";
            $this->log->info($status);

            $status = $fbData['postCount']  ? "    + Text post count added" : "    - Text post count not found";
            $this->log->info($status);
            $status = $fbData['imageCount'] ? "    + Image count added"     : "    - Image count not found";
            $this->log->info($status);
            $status = $fbData['videoCount'] ? "    + Video count added"     : "    - Video count not found";
            $this->log->info($status);
            $status = $fbData['eventCount'] ? "    + Event count added"     : "    - Event count not found";
            $this->log->info($status);

            $this->log->info("  + All Facebook statistics processed");

            if (!isset($fbData['cover'])) {
                $this->log->notice("  - No Facebook cover found for " . $partyCode);
            } else {
                $cover = $this->container->get('ImageService')
                    ->getFacebookCover($partyCode, $fbData['cover']);
                $this->log->info("    + Cover retrieved");

                $this->db->addMeta(
                    $partyCode,
                    Metadata::TYPE_FACEBOOK_COVER,
                    $cover
                );
                $this->log->info("      + Cover added");
            }
        }

        if ($this->scrapeData == null || $this->scrapeData == 'posts') {
            $status = !empty($fbData['posts'])  ? "    + Text posts added" : "    - No text posts found";
            $this->log->info($status);
            $status = !empty($fbData['videos']) ? "    + Videos added"     : "    - No videos found";
            $this->log->info($status);
        }
        if ($this->scrapeData == null || $this->scrapeData == 'images') {
            $status = !empty($fbData['images']) ? "    + Images added"     : "    - No images found";
            $this->log->info($status);
        }
        if ($this->scrapeData == null || $this->scrapeData == 'events') {
            $status = !empty($fbData['events']) ? "    + Events added"     : "    - No events found";
            $this->log->info($status);
        }

        $this->log->info("  + All Facebook data processed");
    }



    /**
     * TWITTER
     * @param string $partyCode
     * @param array  $socialNetworks
     */
    public function scrapeTwitter($partyCode, $socialNetworks)
    {
        if (empty($socialNetworks['twitter']) || empty($socialNetworks['twitter']['username'])) {
            $this->log->warning(" - Twitter data not found for " . $partyCode);
            return false;
        }

        $this->log->info("  + Starting Twitter import");
        $twData = $this->container->get('TwitterService')
            ->getTwitterData($partyCode, $socialNetworks['twitter']['username'], $this->scrapeFull);

        if (!$twData) {
            $this->log->notice("  - ERROR while retrieving Twitter data for " . $partyCode);
            return false;
        }

        $this->log->info("    + Twitter data retrieved");

        $status = isset($twData['description']) ? "    + Description added"     : "    - Description not found";
        $this->log->info($status);
        $status = isset($twData['likes'])       ? "    + 'Like' count added"    : "    - 'Like' count not found";
        $this->log->info($status);
        $status = isset($twData['followers'])   ? "    + Follower count added"  : "    - Follower count not found";
        $this->log->info($status);
        $status = isset($twData['following'])   ? "    + Following count added" : "    - Following count not found";
        $this->log->info($status);
        $status = isset($twData['tweets'])      ? "    + Tweet count added"     : "    - Tweet count not found";
        $this->log->info($status);

        $this->log->info("  + All Twitter statistics processed");

        $status = !empty($twData['posts'])  ? "    + Text tweets added" : "    - No text tweets found";
        $this->log->info($status);
        $status = !empty($twData['images']) ? "    + Images added"      : "    - No images found";
        $this->log->info($status);
        $status = !empty($twData['videos']) ? "    + Videos added"      : "    - No videos found";
        $this->log->info($status);

        $this->log->info("  + All Twitter data processed");
    }


    /**
     * GOOGLE PLUS
     * @param string $partyCode
     * @param array  $socialNetworks
     */
    public function scrapeGooglePlus($partyCode, $socialNetworks)
    {
        if (empty($socialNetworks['googlePlus'])) {
            $this->log->warning(" - Google+ data not found for " . $partyCode);
            return false;
        }

        $this->log->info("  + Starting Google+ import");
        $gData = $this->container->get('GoogleService')
            ->getGooglePlusData($partyCode, $socialNetworks['googlePlus']);

        if (empty($gData)) {
            $this->log->notice("  - ERROR while retrieving Google+ data for " . $partyCode);
            return false;
        }

        $this->log->info("    + Google+ data retrieved");
        $this->log->info("      + Follower count added");
    }


    /**
     * YOUTUBE
     * @param string $partyCode
     * @param array  $socialNetworks
     */
    public function scrapeYoutube($partyCode, $socialNetworks)
    {
        if (empty($socialNetworks['youtube'])) {
            $this->log->warning(" - Youtube data not found for " . $partyCode);
            return false;
        }

        $this->log->info("  + Starting Youtube import");
        $ytData = $this->container->get('GoogleService')
            ->getYoutubeData($partyCode, $socialNetworks['youtube']);

        if (!$ytData) {
            $this->log->notice("  - ERROR while retrieving Youtube data for " . $partyCode);
            return false;
        }

        $this->log->info("  + Youtube data retrieved");

        $status = isset($ytData['subCount'])  ? "    + Subscriber count added" : "    - Subscriber count not found";
        $this->log->info($status);
        $status = isset($ytData['viewCount']) ? "    + View count added"       : "    - View count not found";
        $this->log->info($status);
        $status = isset($ytData['vidCount'])  ? "    + Video count added"      : "    - Video count not found";
        $this->log->info($status);

        $this->log->info("  + All Youtube statistics processed");

        $status = !empty($ytData['videos']) ? "    + Videos added" : "    - No videos found";
        $this->log->info($status);

        $this->log->info("  + All Google data processed");
    }

}
