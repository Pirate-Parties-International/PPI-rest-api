<?php
namespace AppBundle\Services;

use Symfony\Component\DependencyInjection\Container;

use AppBundle\Entity\Metadata;

class VerificationService
{
    private   $container;
    protected $db;
    protected $log;

    public function __construct(Container $container) {
        $this->container = $container;
        $this->db        = $this->container->get('DatabaseService');
        $this->log       = $this->container->get('logger');
        @set_exception_handler(array($this->container->get('ConnectionService'), 'exception_handler'));
    }


    /**
     * Verifies the input arguments
     * @param  object $input
     * @return array
     */
    public function verifyInput($input) {
        $options = [
            'party'  => $input->getOption('party'),  // if null, get all
            'site'   => $input->getOption('site'),   // if null, get all
            'data'   => $input->getOption('data'),   // if null, get all
            'resume' => $input->getOption('resume'), // if null, get all
            'full'   => $input->getOption('full'),   // if null, get most recent posts only
            ];

        if ($options['full']) {
            $this->log->notice("### Full scrape requested, will overwrite database!");
        }

        switch ($options['site']) {
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
                $this->log->error("- ERROR: Search term \"" . $options['site'] . "\" not recognised");
                $this->log->notice("# Process halted");
                exit;
        }

        $dataName = "all data";

        if ($options['data']) {
            switch ($options['data']) {
                case 'info':
                case 'data':
                case 'basic':
                case 'stats':
                    $options['data'] = 'info';
                    $dataName = "basic information and stats only";
                    break;
                case 'posts':
                case 'text':
                case 'statuses':
                    $options['data'] = 'posts';
                    $dataName = "text posts and videos only";
                    break;
                case 'photos':
                case 'images':
                case 'pictures':
                    $options['data'] = 'images';
                    $dataName = "images only";
                    break;
                case 'events':
                    $options['data'] = 'events';
                    $dataName = "events only";
                    break;
                case 'videos':
                    $this->log->error("- ERROR: Videos are included with text posts and can not be scraped separately");
                    $this->log->notice("# Process halted");
                    exit;
                default:
                    $this->log->error("- ERROR: Search term \"" . $options['data'] . "\" is not valid");
                    $this->log->notice("# Process halted");
                    exit;
            }

            if ($options['data'] != 'info') {
                switch ($options['site']) {
                    case 'fb':
                    case null:
                        $options['site'] = 'fb';
                        $siteName = "Facebook";
                        break;
                    default:
                        $this->log->error("- ERROR: Search term \"" . $options['data'] . "\" is only valid for Facebook");
                        $this->log->notice("# Process halted");
                        exit;
                }
            }

        }

        $this->log->info("### Scraping " . $siteName . " for " . $dataName);

        return $options;
    }


    /**
     * Verifies data scraped from Facebook
     * @param  array  $fbData
     * @param  string $scrapeData
     * @return null
     */
    public function verifyFbData($fbData, $scrapeData) {
        if ($scrapeData == null || $scrapeData == 'info') {
            $status = isset($fbData['info'])    ? "    + General info added"          : "      - General info is null";
            $this->log->info($status);
            $status = isset($fbData['likes'])   ? "    + 'Like' count added"          : "      - 'Like' count is null";
            $this->log->info($status);
            $status = isset($fbData['talking']) ? "    + 'Talking about' count added" : "      - 'Talking about' count is null";
            $this->log->info($status);

            $status = $fbData['postCount']  ? "    + Text post count added" : "      - Text post count is null";
            $this->log->info($status);
            $status = $fbData['imageCount'] ? "    + Image count added"     : "      - Image count is null";
            $this->log->info($status);
            $status = $fbData['videoCount'] ? "    + Video count added"     : "      - Video count is null";
            $this->log->info($status);
            $status = $fbData['eventCount'] ? "    + Event count added"     : "      - Event count is null";
            $this->log->info($status);

            $this->log->info("  + All Facebook statistics processed");

            $status = $fbData['cover'] ? "    + Cover added" : "      - Cover not found";
            $this->log->info($status);
        }

        if ($scrapeData == null || $scrapeData == 'posts') {
            $status = !empty($fbData['posts'])  ? "    + Text posts added" : "      - No text posts found";
            $this->log->info($status);
            $status = !empty($fbData['videos']) ? "    + Videos added"     : "      - No videos found";
            $this->log->info($status);
        }
        if ($scrapeData == null || $scrapeData == 'images') {
            $status = !empty($fbData['images']) ? "    + Images added"     : "      - No images found";
            $this->log->info($status);
        }
        if ($scrapeData == null || $scrapeData == 'events') {
            $status = !empty($fbData['events']) ? "    + Events added"     : "      - No events found";
            $this->log->info($status);
        }
    }


    /**
     * Verifies data scraped from Twitter
     * @param  array $twData
     * @return null
     */
    public function verifyTwData($twData) {
        $status = isset($twData['description']) ? "    + Description added"     : "      - Description is null";
        $this->log->info($status);
        $status = isset($twData['likes'])       ? "    + 'Like' count added"    : "      - 'Like' count is null";
        $this->log->info($status);
        $status = isset($twData['followers'])   ? "    + Follower count added"  : "      - Follower count is null";
        $this->log->info($status);
        $status = isset($twData['following'])   ? "    + Following count added" : "      - Following count is null";
        $this->log->info($status);
        $status = isset($twData['tweets'])      ? "    + Tweet count added"     : "      - Tweet count is null";
        $this->log->info($status);

        $this->log->info("  + All Twitter statistics processed");

        $status = !empty($twData['posts'])  ? "    + Text tweets added" : "      - No text tweets found";
        $this->log->info($status);
        $status = !empty($twData['images']) ? "    + Images added"      : "      - No images found";
        $this->log->info($status);
        $status = !empty($twData['videos']) ? "    + Videos added"      : "      - No videos found";
        $this->log->info($status);
    }


    /**
     * Verifies data scraped from Youtube
     * @param  array $ytData
     * @return null
     */
    public function verifyYtData($ytData) {
        $status = isset($ytData['subCount'])  ? "    + Subscriber count added" : "      - Subscriber count is null";
        $this->log->info($status);
        $status = isset($ytData['viewCount']) ? "    + View count added"       : "      - View count is null";
        $this->log->info($status);
        $status = isset($ytData['vidCount'])  ? "    + Video count added"      : "      - Video count is null";
        $this->log->info($status);

        $this->log->info("  + All Youtube statistics processed");

        $status = !empty($ytData['videos']) ? "    + Videos added" : "      - No videos found";
        $this->log->info($status);
    }

}