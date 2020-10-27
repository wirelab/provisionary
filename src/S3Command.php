<?php

namespace Wirelab\Provisionary\Console;

use Aws\Exception\AwsException;
use Aws\Iam\IamClient;
use Aws\S3\S3Client;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

class S3Command extends Command
{
    protected $profile = 'default';

    protected $region = 'eu-west-1';

    protected $s3client;

    protected $iamClient;

    protected $createdBuckets = [];

    protected $createdPolicies = [];

    protected $createdUsers = [];

    protected $hasError = false;

    /**
     * {@inheritDoc}
     */
    protected function configure()
    {
        $this
            ->setName('s3')
            ->setDescription('Provision and S3 bucket')
            ->addArgument('name')
            ->addOption('cloudfront', null, InputOption::VALUE_NONE, 'Provisions a Cloudfront distribution for the newly created bucket')
            ->addOption('cleanup', null, InputOption::VALUE_NONE, 'Deletes created resources');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if($input->getOption('cloudfront')) {
            // TODO Cloudfront
        }

        // TODO check if aws is installed

        // Configure profile
        $this->setupAwsProfile($input, $output);

        // Call for configuration of aws client profile
        $output->writeln('<comment>The tool will ask to configure AWS, if you have already configured a profile with the given name just hit enter a couple of times</comment>');
        $process = Process::fromShellCommandline('aws configure --profile ' . $this->profile);
        $process->setTty(true);
        $process->start();
        $process->wait();

        // Ask for region
        $this->setupRegion($input, $output);

        // Setup s3 client
        $this->setupS3Client();
        $this->setupIamClient();

        // To separate or not to separate
        $separateEnvs = (new SymfonyStyle($input, $output))
            ->confirm('Do you want to create separate buckets for dev/acceptance/production?', true);

        foreach (($separateEnvs ? ['dev', 'acceptance', 'production'] : ['shared']) as $environment) {
            $name = $input->getArgument('name') . "-$environment";
            $this->createBucket($name, $input, $output);
            $this->createPolicy($name, $input, $output);

            $this->createUser($name, $input, $output);
            $this->attachPolicy($name, $input, $output);
        }

        // Cleanup created resources
        if($input->getOption('cleanup') || $this->hasError) {
            $output->writeln('<comment>Run cleanup as flag was passed or an error was caught creating resources</comment>');
            $this->cleanup($input, $output);
        }

        return 1;
    }

    /**
     * @param string $name
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    protected function createBucket(string $name, InputInterface $input, OutputInterface $output)
    {
        $result = $this->call($this->s3client, 'createBucket', [
            'Bucket' => $name,
        ], $input, $output);

        if($result) $this->createdBuckets[] = $name;
    }

    /**
     * @param string $name
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    protected function createPolicy(string $name, InputInterface $input, OutputInterface $output)
    {
        $policy = file_get_contents('./policies/IamUser.json');
        $policy = str_replace('{BUCKET_NAME}', $name, $policy);

        $result = $this->call($this->iamClient, 'createPolicy', [
            'PolicyName' => $name,
            'PolicyDocument' => $policy
        ], $input, $output);

        $this->createdPolicies[$name] = $result->get('Policy');
    }

    protected function attachPolicy(string $name, InputInterface $input, OutputInterface $output)
    {
        $this->call($this->iamClient, 'attachUserPolicy', [
            'UserName' => $name,
            'PolicyArn' => $this->createdPolicies[$name]['Arn']
        ], $input, $output);
    }

    /**
     * @param string $name
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    protected function cleanup(InputInterface $input, OutputInterface $output)
    {
        foreach($this->createdUsers as $user) {
            $this->call($this->iamClient, 'detachUserPolicy', [
                'UserName' => $user,
                'PolicyArn' => $this->createdPolicies[$user]['Arn']
            ], $input, $output);

            $this->call($this->iamClient, 'deleteUser', [
                'UserName' => $user
            ], $input, $output);
        }

        foreach ($this->createdPolicies as $policy) {
            $this->call($this->iamClient, 'deletePolicy', [
                'PolicyArn' => $policy['Arn']
            ], $input, $output);
        }

        foreach($this->createdBuckets as $bucket) {
            $this->call($this->s3client, 'deleteBucket', [
                'Bucket' => $bucket
            ], $input, $output);
        }
    }

    /**
     * @param string $name
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    protected function createUser(string $name, InputInterface $input, OutputInterface $output)
    {
        $this->call($this->iamClient, 'createUser', [
            'UserName' => $name,
        ], $input, $output);

        $this->createdUsers[] = $name;
    }

    protected function setupS3Client()
    {
        $this->s3client = new S3Client([
            'profile' => $this->profile,
            'version' => '2006-03-01',
            'region'  => $this->region
        ]);
    }

    protected function setupIamClient()
    {
        $this->iamClient = new IamClient([
            'profile' => $this->profile,
            'version' => '2010-05-08',
            'region'  => $this->region
        ]);
    }

    protected function setupAwsProfile($input, $output)
    {
        $helper = $this->getHelper('question');
        $question = new Question("Which AWS profile should we use? (defaults to {$this->profile})", $this->profile);
        $this->profile = $helper->ask($input, new SymfonyStyle($input, $output), $question);
    }

    protected function setupRegion($input, $output)
    {
        $helper = $this->getHelper('question');
        $question = new Question("Please enter the region of choice for the buckets (defaults to {$this->region}) ", $this->region);
        $this->region = $helper->ask($input, new SymfonyStyle($input, $output), $question);
    }

    /**
     * @param $client
     * @param string $method
     * @param array $data
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    protected function call($client, string $method, array $data, InputInterface $input, OutputInterface $output)
    {
        try {
            $result = $client->{$method}($data);

            $output->writeLn("Successfully called $method for resource with data");

            return $result;
        } catch (AwsException $e) {
            $output->writeln("<error>Error calling $method </error>");
            $output->writeln($e->getAwsErrorMessage());
            $output->writeln($e->getMessage());
            $this->hasError = true;
        }
    }

}