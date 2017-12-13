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
    protected $logger;
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
            return false;
        }

        $this->output->writeln("   + Starting Facebook import");
        $fbData = $this->container->get('FacebookService')
            ->getFBData($partyCode, $socialNetworks['facebook']['username'], $this->scrapeFull, $this->scrapeData);

        if (!$fbData) {
            $this->output->writeln("     - ERROR while retrieving FB data");
            return false;
        }

        $this->output->writeln("   + Facebook data retrieved");

        if ($this->scrapeData == null || $this->scrapeData == 'info') {
            $status = isset($fbData['info'])    ? "+ General info added"          : "- General info not found";
            $this->output->writeln("     " . $status);
            $status = isset($fbData['likes'])   ? "+ 'Like' count added"          : "- 'Like' count not found";
            $this->output->writeln("     " . $status);
            $status = isset($fbData['talking']) ? "+ 'Talking about' count added" : "- 'Talking about' count not found";
            $this->output->writeln("     " . $status);

            $status = $fbData['postCount']  ? "+ Text post count added" : "- Text post count not found";
            $this->output->writeln("     " . $status);
            $status = $fbData['imageCount'] ? "+ Image count added"     : "- Image count not found";
            $this->output->writeln("     " . $status);
            $status = $fbData['videoCount'] ? "+ Video count added"     : "- Video count not found";
            $this->output->writeln("     " . $status);
            $status = $fbData['eventCount'] ? "+ Event count added"     : "- Event count not found";
            $this->output->writeln("     " . $status);

            $this->output->writeln("   + All statistics processed");

            if (!isset($fbData['cover'])) {
                $this->output->writeln("     - No cover found");
            } else {
                $cover = $this->container->get('ImageService')
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
            $status = !empty($fbData['posts'])  ? "+ Text posts added" : "- No text posts found";
            $this->output->writeln("     " . $status);
            $status = !empty($fbData['videos']) ? "+ Videos added"     : "- No videos found";
            $this->output->writeln("     " . $status);
        }
        if ($this->scrapeData == null || $this->scrapeData == 'images') {
            $status = !empty($fbData['images']) ? "+ Images added"     : "- No images found";
            $this->output->writeln("     " . $status);
        }
        if ($this->scrapeData == null || $this->scrapeData == 'events') {
            $status = !empty($fbData['events']) ? "+ Events added"     : "- No events found";
            $this->output->writeln("     " . $status);
        }

        $this->output->writeln("   + All Facebook data processed");
    }



    /**
     * TWITTER
     * @param string $partyCode
     * @param array  $socialNetworks
     */
    public function scrapeTwitter($partyCode, $socialNetworks)
    {
        if (empty($socialNetworks['twitter']) || empty($socialNetworks['twitter']['username'])) {
            $this->output->writeln("   - Twitter data not found");
            return false;
        }

        $this->output->writeln("   + Starting Twitter import");
        $twData = $this->container->get('TwitterService')
            ->getTwitterData($partyCode, $socialNetworks['twitter']['username'], $this->scrapeFull);

        if (!$twData) {
            $this->output->writeln("     - ERROR while retrieving TW data");
            return false;
        }

        $this->output->writeln("   + Twitter data retrieved");

        $status = isset($twData['description']) ? "+ Description added"     : "- Description not found";
        $this->output->writeln("     " . $status);
        $status = isset($twData['likes'])       ? "+ 'Like' count added"    : "- 'Like' count not found";
        $this->output->writeln("     " . $status);
        $status = isset($twData['followers'])   ? "+ Follower count added"  : "- Follower count not found";
        $this->output->writeln("     " . $status);
        $status = isset($twData['following'])   ? "+ Following count added" : "- Following count not found";
        $this->output->writeln("     " . $status);
        $status = isset($twData['tweets'])      ? "+ Tweet count added"     : "- Tweet count not found";
        $this->output->writeln("     " . $status);

        $this->output->writeln("   + All statistics processed");

        $status = !empty($twData['posts'])  ? "+ Text tweets added" : "- No text tweets found";
        $this->output->writeln("     " . $status);
        $status = !empty($twData['images']) ? "+ Images added"      : "- No images found";
        $this->output->writeln("     " . $status);
        $status = !empty($twData['videos']) ? "+ Videos added"      : "- No videos found";
        $this->output->writeln("     " . $status);

        $this->output->writeln("   + All Twitter data processed");
    }


    /**
     * GOOGLE PLUS
     * @param string $partyCode
     * @param array  $socialNetworks
     */
    public function scrapeGooglePlus($partyCode, $socialNetworks)
    {
        if (empty($socialNetworks['googlePlus'])) {
            $this->output->writeln("   - Google+ data not found");
            return false;
        }

        $this->output->writeln("   + Starting Google+ import");
        $gData = $this->container->get('GoogleService')
            ->getGooglePlusData($partyCode, $socialNetworks['googlePlus']);

        if (empty($gData)) {
            $this->output->writeln("     - ERROR while retrieving G+ data");
            return false;
        }

        $this->output->writeln("     + Google+ data retrieved");
        $this->output->writeln("     + Follower count added");
    }


    /**
     * YOUTUBE
     * @param string $partyCode
     * @param array  $socialNetworks
     */
    public function scrapeYoutube($partyCode, $socialNetworks)
    {
        if (empty($socialNetworks['youtube'])) {
            $this->output->writeln("   - Youtube data not found");
            return false;
        }

        $this->output->writeln("   + Starting Youtube import");
        $ytData = $this->container->get('GoogleService')
            ->getYoutubeData($partyCode, $socialNetworks['youtube']);

        if (!$ytData) {
            $this->output->writeln("     - ERROR while retrieving Youtube data");
            return false;
        }

        $this->output->writeln("   + Youtube data retrieved");

        $status = isset($ytData['stats']['subCount'])  ? "+ Subscriber count added" : "- Subscriber count not found";
        $this->output->writeln("     " . $status);
        $status = isset($ytData['stats']['viewCount']) ? "+ View count added"       : "- View count not found";
        $this->output->writeln("     " . $status);
        $status = isset($ytData['stats']['vidCount'])  ? "+ Video count added"      : "- Video count not found";
        $this->output->writeln("     " . $status);

        $this->output->writeln("   + All statistics processed");

        $status = !empty($ytData['videos']) ? "+ Videos added" : "- No videos found";
        $this->output->writeln("     " . $status);

        $this->output->writeln("   + All Google data processed");
    }

}
