<?php
namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use AppBundle\Entity\SocialMedia;
use Pirates\PapiInfo\Compile;

class PatchCommand extends ContainerAwareCommand
{
	protected function configure() {
		$this
			->setName('papi:patch')
			->setDescription('Patches existing db entries')
            ->addOption('twitter',  't', InputOption::VALUE_NONE, "Fix 'postId' field in Twitter images and videos")
            ->addOption('charset',  'c', InputOption::VALUE_NONE, "Convert 'postText' field to utf8mb4 character set")
            ->addOption('postdata', 'p', InputOption::VALUE_NONE, "Rename certain 'postData' array keys for consistency")
            ->addOption('stats',    'x', InputOption::VALUE_NONE, "Alter Twitter and Facebook stat codes for consistency")
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output) {
        $this->container = $this->getContainer();
        $this->em = $this->container->get('doctrine')->getManager();

        $this->output = $output;
        $this->logger = $this->getContainer()->get('logger');
        $logger = $this->logger;

        switch (true) { // add more options here
            case $input->getOption('twitter'):
                $output->writeln("##### Patching Twitter images #####");
                $this->getConfirmation();
                $this->patchTwitterImages();
                break;
            case $input->getOption('charset'):
                $output->writeln("##### Patching 'postText' charset #####");
                $this->patchCharset();
                break;
            case $input->getOption('postdata'):
                $output->writeln("##### Patching 'postData' array keys #####");
                $this->getConfirmation();
                $this->patchPostData();
                break;
            case $input->getOption('stats');
                $output->writeln("##### Patching stat codes #####");
                $this->patchStatCodes();
                break;
            default:
                $output->writeln("Invalid option.");
        }
    }

    public function getConfirmation() {
        echo "### CAUTION: THIS WILL ALTER THE DATABASE! ###\n";
        echo "  It is recommended to make a backup of your database before performing this action.\n";
        echo "  Do you wish to continue? y/n - ";

        $handle = fopen ("php://stdin","r");
        $line = fgets($handle);

        if (trim($line) != 'y' && trim($line) != 'yes'){
            echo "  Process aborted.\n";
            exit;
        }
    }


/////
// stat codes (--stats, -x)
//
// The scraper originally logged stats for tw-T (for 'Tweets').
// When we started scraping Facebook too, this was changed to P (for 'Posts') as fb-T was used for the 'Talking About' stat.
// The scraper has been altered to log them as T again (for 'Text') to make them consistent with the older stats.
// This patch changes those stats that are already in the database back to T, and changes fb-T to fb-A.
/////
    public function patchStatCodes() {
        $posts = $this->em->getRepository('AppBundle:Statistic')->findBy(['subType' => 'P']);

        if (empty($posts)) {
            echo "  The database is up to date. No patch needed.\n";
            exit;
        }

        $this->getConfirmation();

        echo "  Checking tweets... ";
        $tweets = $this->em->getRepository('AppBundle:Statistic')->findBy(['type' => 'tw', 'subType' => 'P']);

        if (!empty($tweets)) {
            echo "patching...";

            foreach ($tweets as $tweet) {
                $tweet->setSubType('T');
                $this->em->persist($tweet);
                echo ".";
            }

            echo "\n    All patched, saving to DB... ";
            $this->em->flush();
            echo "done.\n";

        } else echo "no patch needed.\n";

        echo "  Checking Facebook statuses... ";
        $statuses = $this->em->getRepository('AppBundle:Statistic')->findBy(['type' => 'fb', 'subType' => 'P']);

        if (!empty($statuses)) {
            echo "patching...";

            $talking = $this->em->getRepository('AppBundle:Statistic')->findBy(['type' => 'fb', 'subType' => 'T']);
            foreach ($talking as $talk) {
                $talk->setSubType('A');
                $this->em->persist($talk);
                echo ".";
            }

            foreach ($statuses as $status) {
                $status->setSubType('T');
                $this->em->persist($status);
                echo ".";
            }

            echo "\n    All patched, saving to DB... ";
            $this->em->flush();
            echo "done.\n";

        } else echo "no patch needed.\n";

        echo "  All done.\n";
    }


/////
// postData array keys (--postdata, -p)
//
// The scraper originally logged data from different sources using each site's original term for the fields.
// e.g. Twitter has 'text' where Facebook has 'message', 'story' or 'caption', depending on the post type.
// The scraper has been updated to use consistent field names for all sites, making it easier for the api.
// This patch alters these fields for posts already in the database.
/////
    public function patchPostData() {
        echo "  Getting posts from DB...\n";
        $socialMedia = $this->em->getRepository('AppBundle:SocialMedia')->findAll();
        echo "  Patching...";
 
        foreach ($socialMedia as $social) {
            $temp = $social->getPostData();
            $type = $social->getType();
            $img  = (null != $social->getPostImage()) ? $social->getPostImage() : null;

            if (isset($temp['img_source'])) {
                // no patch needed, do nothing
            } else if ($type != 'tw' && isset($temp['text'])) {
                // no patch needed, do nothing

            } else {
                if ($type == 'tw' && !is_null($img)) {
                    $temp['img_source'] = $temp['image'];
                    $temp['image'] = $img;
                    echo ".";

                } else if ($type == 'yt') {
                    $temp['text'] = $temp['title'];
                    $temp['image'] = $img;
                    $temp['img_source'] = $temp['thumb'];
                    unset($temp['title']);
                    unset($temp['thumb']);
                    echo ".";

                } else if ($type == 'fb') {
                    switch ($social->getSubType()) {
                        case 'I': // images
                            $temp['text'] = $temp['caption'];
                            $temp['image'] = $img;
                            $temp['img_source'] = $temp['source'];
                            unset($temp['caption']);
                            unset($temp['source']);
                            echo ".";
                            break;

                        case 'E': // events
                            $temp['text'] = $temp['name'];
                            $temp['description'] = $temp['details']['description'];
                            $temp['image'] = $img;
                            $temp['img_source'] = $temp['details']['cover']['source'];
                            $temp['place'] = $temp['details']['place'];
                            $temp['address'] = $temp['details']['address'];
                            unset($temp['name']);
                            unset($temp['details']);
                            echo ".";
                            break;

                        case 'T': // text posts
                        case 'V': // videos
                            $story = isset($temp['story']) ? $temp['story'] : null;
                            $temp['text'] = isset($temp['message']) ? $temp['message'] : $story;
                            $temp['image'] = $img;
                            $temp['img_source'] = isset($temp['link']['thumb']) ? $temp['link']['thumb'] : null;
                            unset($temp['message']);
                            unset($temp['story']);
                            unset($temp['link']['thumb']);
                            echo ".";
                    }
                }
                $social->setPostData($temp);
                $this->em->persist($social);
            }
        }
        echo "\n  All patched. Saving to DB...\n";
        $this->em->flush();
        echo "  Done.\n";
    }


/////
// Charset for 'postText' field (--charset, -c)
//
// The database was originally encoded to utf8, which is the standard.
// This made it impossible to log 4-byte characters, such as emojis or certain languages like Japanese and Chinese.
// This meant that the scraper often failed when trying to save social media posts.
// This patch alters the social media postText field to use utf8mb4, which supports 4-byte characters.
/////
    public function patchCharset() {
    	$old = $this->checkCharset();

		if ($old['char'] == 'utf8mb4' && $old['data'] == 'longtext') {
			echo ", no fix needed.\n";
			return;
		}

		echo ", fix needed.\n";
		$this->getConfirmation();
		$this->fixCharset();
		$new = $this->checkCharset();

		if ($new['char'] == 'utf8mb4' && $new['data'] == 'longtext') {
			echo ", great success! :D\n";
			return;
		}

        echo ", process failed. :(\n";
        echo "  Your database may need to be altered manually. Contact us on github if you need help.\n";
    }

    /**
     * Checks current charset
     */
    public function checkCharset() {
    	$db_name = $this->container->getParameter('database_name');

    	$sql1 = "SELECT character_set_name
    		FROM information_schema.columns
    		WHERE table_schema = '$db_name'
    		AND table_name = 'social_media'
    		AND column_name = 'postText';";

    	$query = $this->em->getConnection()->prepare($sql1);
        $query->execute();
        $answer['char'] = $query->fetchAll();

        $sql2 = "SELECT data_type
            FROM information_schema.columns
            WHERE table_schema = '$db_name'
            AND table_name = 'social_media'
            AND column_name = 'postText';";

        $query = $this->em->getConnection()->prepare($sql2);
        $query->execute();
        $answer['data'] = $query->fetchAll();

    	$array['char'] = $answer['char'][0]['character_set_name'];
        $array['data'] = $answer['data'][0]['data_type'];
		echo "  Current character set is ".$array['char'].", data type is ".$array['data'];
		return $array;
    }

    /**
     * Converts charset to 4-byte compatible utf8mb4
     */
    public function fixCharset() {
    	$sql = "ALTER TABLE social_media
    		CHANGE postText postText LONGTEXT
    		CHARACTER SET utf8mb4
    		COLLATE utf8mb4_unicode_ci;";

        $query = $this->em->getConnection()->prepare($sql);
        $query->execute();
    }


/////
// Twitter images (--twitter, -t)
//
// The scraper originally logged incorrect IDs for linking back to Twitter images and videos.
// The scraper has been updated to correct this issue.
// This patch fixes old databse entries by taking the correct data from the postUrl and applying it to the postId.
/////
    public function patchTwitterImages() {
        $smRepo = $this->em->getRepository('AppBundle:SocialMedia');
        $twImgs = $smRepo->findBy(['type' => 'tw', 'subType' => 'i']);
        $twVids = $smRepo->findBy(['type' => 'tw', 'subType' => 'v']);

		foreach ($twImgs as $img) {
			$this->getPostIdFromUrl($img);
		}

		foreach ($twVids as $vid) {
			$this->getPostIdFromUrl($vid);
		}

		$this->em->flush();
		echo "  All done.\n";
	}

	/**
	 * Validates postId by comparing to postUrl
	 * Replaces postId if invalid
	 */
	public function getPostIdFromUrl($post) {
		$oldId = $post->getPostId();
		echo "  postId = ".$oldId;
		$postData = $post->getPostData();
		$postUrl = $postData['url'];
		$newId = substr($postUrl, -18);
		echo ", postUrl = ".$postUrl.", newId = ".$newId;

		if ($oldId !== $newId) {
			echo ", replacing...\n";
			$post->setPostId($newId);
			$this->em->persist($post);
		} else {
			echo ", no fix needed.\n";
		}
	}

}