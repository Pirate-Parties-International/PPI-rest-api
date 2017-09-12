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
	protected function configure()
	{
		$this
			->setName('papi:patch')
			->setDescription('Patches existing db entries')
            ->addOption('twitter',  't', InputOption::VALUE_NONE, "Fix 'postId' field in twitter images and videos")
            ->addOption('charset',  'c', InputOption::VALUE_NONE, "Convert 'postText' field to utf8mb4 character set")
            ->addOption('postdata', 'p', InputOption::VALUE_NONE, "Rename certain 'postData' array keys for consistency")
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->container = $this->getContainer();
        $this->em = $this->container->get('doctrine')->getManager();

        $this->output = $output;
        $this->logger = $this->getContainer()->get('logger');
        $logger = $this->logger;

        switch (true) { // add more options here
            case $input->getOption('twitter'):
                $output->writeln("##### Patching twitter images #####");
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
        } else {
            echo "\n";
        }
    }


/////
// postData array keys
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
// Charset for 'postText' field
/////
    public function patchCharset() {
        $charset = $this->checkCharset();

        if ($charset == 'utf8mb4') {
            echo ", no fix needed.\n";
            return;
        }

        echo ", fix needed.\n";
        $this->getConfirmation();
        $this->fixCharset();
        $newCharset = $this->checkCharset();

        if ($newCharset == 'utf8mb4') {
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

        $sql = "SELECT character_set_name
            FROM information_schema.columns
            WHERE table_schema = '$db_name'
            AND table_name = 'social_media'
            AND column_name = 'postText';";

        $query = $this->em->getConnection()->prepare($sql);
        $query->execute();
        $answer = $query->fetchAll();
        $charset = $answer[0]['character_set_name'];
        echo "  Current character set for postText = ".$charset;
        return $charset;
    }

    /**
     * Converts charset to 4-byte compatible utf8mb4
     */
    public function fixCharset() {
        $sql = "ALTER TABLE social_media
            CHANGE postText postText VARCHAR(191)
            CHARACTER SET utf8mb4
            COLLATE utf8mb4_unicode_ci;";

        $query = $this->em->getConnection()->prepare($sql);
        $query->execute();
    }


/////
// Twitter images
/////

    /**
     * Finds all Twitter images and videos to patch
     */
    public function patchTwitterImages()
    {
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
	public function getPostIdFromUrl($post)
	{
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