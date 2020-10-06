<?php

require '/.vendor/autoload.php';


use Aws\Ec2\Ec2Client;
use Aws\Rds\RdsClient; 
use Aws\S3\S3Client;
use Aws\CloudWatch\CloudWatchClient; 
use Aws\AutoScaling\AutoScalingClient;
use Aws\Exception\AwsException;



// Bucket configuration
define('BUCKET_NAME', 'zohaib');
define('REGION', 'us-east-1');
define('DESKTOP_PATH', 'C:/Users/user/Desktop');

//db configuration

define('RDS_HOSTNAME','mp3.chvrcdwgvt0o.us-east-1.rds.amazonaws.com');
define('RDS_NAME','mp3');
define('RDS_USER','zohaib');
define('RDS_PASSWORD','syedzohaibali');
define('RDS_CLASS','db.t2.micro');
define('RDS_STORAGE', 5);
define('RDS_ENGINE','MySQL');



$ec2Client = Ec2Client::factory(array(
  'version'     => 'latest',	
  'region'      => REGION,
));

// Create the key pair
echo "Creating the key pair";
echo "<br>";
$keyPairName = 'my-keypair-ec2';
$result = $ec2Client->createKeyPair(array(
    'KeyName' => $keyPairName
));


// Save the private key
echo "Saving the private key into given path i.e.".DESKTOP_PATH;
echo "<br>";
$saveKeyLocation = DESKTOP_PATH. "/.ssh/{$keyPairName}.pem";

$data = $result->toArray()['KeyMaterial'];
file_put_contents($saveKeyLocation, $data);

// Update the key's permissions so it can be used with SSH
chmod($saveKeyLocation, 0600);



// Create the security group
echo "Creating the security group";
echo "<br>";
$securityGroupName = 'my-security-group-ec2';
$result = $ec2Client->createSecurityGroup(array(
    'GroupName'   => $securityGroupName,
    'Description' => 'Basic web server security'
));

// Set ingress rules for the security group
echo "Set ingress rules for the security group";
echo "<br>";
$ec2Client->authorizeSecurityGroupIngress(array(
    'GroupName'     => $securityGroupName,
    'IpPermissions' => array(
        array(
            'IpProtocol' => 'tcp',
            'FromPort'   => 80,
            'ToPort'     => 80,
            'IpRanges'   => array(
                array('CidrIp' => '0.0.0.0/0')
            ),
        ),
        array(
            'IpProtocol' => 'tcp',
            'FromPort'   => 22,
            'ToPort'     => 22,
            'IpRanges'   => array(
                array('CidrIp' => '0.0.0.0/0')
            ),
        )
    )
));


echo "Launch an instance with the key pair and security group";
echo "<br>";

$userData= '#!/bin/bash
#https://gist.github.com/aamnah/f03c266d715ed479eb46

# Update packages and Upgrade system
echo -e "Updating System.. "
sudo apt-get update -y && sudo apt-get upgrade -y

## Install AMP
echo -e "Installing Apache2 "
sudo apt-get install -y apache2 apache2-doc apache2-mpm-prefork apache2-utils libexpat1 ssl-cert

echo -e "Installing PHP & Requirements "
sudo apt-get install -y libapache2-mod-php5 php5 php5-common php5-curl php5-dev php5-gd php5-idn php-pear php5-imagick php5-mcrypt php5-mysql php5-ps php5-pspell php5-recode php5-xsl

echo -e "Installing MySQL "
sudo apt-get install -y mysql-server mysql-client libmysqlclient15.dev

echo -e "Installing phpMyAdmin "
sudo DEBIAN_FRONTEND=noninteractive apt-get -yq install phpmyadmin

echo -e "Verifying installs"
sudo apt-get install -y apache2 libapache2-mod-php5 php5 mysql-server php-pear php5-mysql mysql-client mysql-server php5-mysql php5-gd

# Permissions
echo -e "Permissions for /var/www "
sudo chown -R www-data:www-data /var/www
echo -e " Permissions have been set "

# give permission of html folder
sudo chmod a+rwx /var/www/html

#clone project
echo -e "git clone"
git clone  https://github.com/zohaibalvi/mp3.git /var/www/html/mp3
echo -e "git end"


# Enabling Mod Rewrite, required for WordPress permalinks and .htaccess files
echo -e "Enabling Modules "
sudo a2enmod rewrite
sudo php5enmod mcrypt

# Restart Apache
echo -e "Restarting Apache "
sudo service apache2 restart';

$userDataEncoded = base64_encode($userData);


// Launch an instance with the key pair and security group
$result = $ec2Client->runInstances(array(
    'ImageId'        => 'ami-0dba2cb6798deb6d8',
    'MinCount'       => 1,
    'MaxCount'       => 1,
    'InstanceType'   => 't2.micro',//'m1.small',
    'KeyName'        => $keyPairName,
    'SecurityGroups' => array($securityGroupName),
    'UserData'      => $userDataEncoded
));


echo "Wait until the instance is launched";
echo "<br>";


echo '<h1> Now Creating RDS </h1>';
echo "<br>";


//Create a RDSClient
$rdsClient = new Aws\Rds\RdsClient([
    'version'     => 'latest',
    'region'      => REGION,
]);

$dbIdentifier = RDS_NAME;
$dbClass = RDS_CLASS;
$storage = RDS_STORAGE;
$engine = RDS_ENGINE;
$username = RDS_USER;
$password =  RDS_PASSWORD;

try {
    $result = $rdsClient->createDBInstance([
        'DBInstanceIdentifier' => $dbIdentifier,
        'DBInstanceClass' => $dbClass ,
        'AllocatedStorage' => $storage,
        'Engine' => $engine,
        'MasterUsername' => $username,
        'MasterUserPassword' => $password,
    ]);

    echo "<pre>";
    echo "RDS has been created";
    echo "<br>";
} catch (AwsException $e) {
    echo $e->getMessage();
    echo "\n";
} 



echo '<h1> Now Creating s3 bucket </h1>';
echo "<br>";

$s3 = new Aws\S3\S3Client([
        'version'     => 'latest',
        'region'      => REGION,
]);
try {
    $promise = $s3->createBucketAsync([
        'Bucket' => BUCKET_NAME,
        'CreateBucketConfiguration' => [
        'LocationConstraint' => REGION
    ]
]);

    $promise->wait();

} catch (Exception $e) {
    if ($e->getCode() == 'BucketAlreadyExists') {
      exit("\nCannot create the bucket. " .
        "A bucket with the name ".BUCKET_NAME." already exists. Exiting.");
  }
}




// cloudwatch alarm on above created EC2 instance

$client = new CloudWatchClient([
    'profile' => 'default',
    'version'     => 'latest',
    'region'      => REGION,
]);

// create Alaram 
 
 function putMetricAlarm($cloudWatchClient, $cloudWatchRegion, 
    $alarmName, $namespace, $metricName, 
    $dimensions, $statistic, $period, $comparison, $threshold, 
    $evaluationPeriods,$AlarmActions,$AlarmDescription )
{
    try {
        $result = $cloudWatchClient->putMetricAlarm([
            'AlarmName' => $alarmName,
            'Namespace' => $namespace,
            'MetricName' => $metricName,
            'Dimensions' => $dimensions,
            'Statistic' => $statistic,
            'Period' => $period,
            'ComparisonOperator' => $comparison,
            'Threshold' => $threshold,
            'EvaluationPeriods' => $evaluationPeriods,
            'AlarmActions' => $AlarmActions,
            'AlarmDescription' =>$AlarmDescription 
        ]);
        
        if (isset($result['@metadata']['effectiveUri']))
        {
            if ($result['@metadata']['effectiveUri'] == 
                'https://monitoring.' . $cloudWatchRegion . '.amazonaws.com')
            {
                return 'Successfully created or updated specified alarm.';
            } else {
                return 'Could not create or update specified alarm.';
            }
        } else {
            return 'Could not create or update specified alarm.';
        }
    } catch (AwsException $e) {
        return 'Error: ' . $e->getAwsErrorMessage();
    }
}

function putTheMetricAlarm()
{
    $alarmName = 'my-ec2';
    $namespace = 'AWS/EC2';
    $metricName = 'NetworkOut';  // create an alarm on Network Out of above created EC2 intance
    $dimensions = [
        [
            'Name' => 'InstanceId',
            'Value' => 'i-002d8e77d8b*****a'   //Entered instance id of above created EC2
        ]
    ];
    $statistic = 'Average';
    $period = 60;
    $comparison = 'GreaterThanThreshold';
    $threshold = 1;
    $evaluationPeriods = 1;

    $cloudWatchRegion = REGION;

// $AlarmDescription = "CPU Utilization of i-1234567890abcdef0 with 40% as threshold",

  $AlarmActions = [
    // send notification on sns (Gmail)
    'arn:aws:sns:us-east-1:372100151213:MyTopic'

  ];
   $AlarmDescription = "Instance of id i-1234567890abcdef0 outgoing volumne network is about 1byte as threshold";
    $cloudWatchClient = new CloudWatchClient([
        'profile' => 'default',
        'region' => $cloudWatchRegion,
        'version' => 'latest'
    ]);


    echo putMetricAlarm($cloudWatchClient, $cloudWatchRegion, 
        $alarmName, $namespace, $metricName, 
        $dimensions, $statistic, $period, $comparison, $threshold, 
        $evaluationPeriods,$AlarmActions,$AlarmDescription );
}

echo "<pre>";
print_r(putTheMetricAlarm());




// AutoScaling on above created EC2 


$client = AutoScalingClient::factory(array(
    'profile' => 'default',
    'version'     => 'latest',  
    'region'      => 'us-east-1',
));

$LaunchConfigurationName = "MyLaunhConfig-03";
$KeyName = 'my-keypair-ec2';
$SecurityGroups = 'sg-0ac7987fcc3ee9468';
$AutoScalingGroupName = 'AutoScalingGroup-03';
$result = $client->createLaunchConfiguration(array(
    // LaunchConfigurationName is required
    'LaunchConfigurationName' => $LaunchConfigurationName ,
    'ImageId' => 'ami-0513c3e1f5ea987a6',        //create a AMI of above EC2 instance and put the ami id 
    'KeyName' => $KeyName,
    'SecurityGroups' => array($SecurityGroups),
   
    'InstanceType' => 't2.micro',
    
     "BlockDeviceMappings"=> []

));


$result = $client->createAutoScalingGroup(array(
    // AutoScalingGroupName is required
    'AutoScalingGroupName' => $AutoScalingGroupName,
    'LaunchConfigurationName' => $LaunchConfigurationName,
    // MinSize is required
    'MinSize' => 1,
    // MaxSize is required
    'MaxSize' => 1,
    'DesiredCapacity' => 1,
    'HealthCheckType' => 'EC2',
    'VPCZoneIdentifier' => 'subnet-63e59a2e',


    'Tags' => array(
        array(
            // Key is required
            'Key' => 'Name',
            'Value' => 'auto-scaling-webserver',
            'PropagateAtLaunch' => true ,
        ),
    ),
));

// add notification 
$result = $client->putNotificationConfiguration([
    'AutoScalingGroupName' => $AutoScalingGroupName,
    'NotificationTypes'     => [
                                "autoscaling:EC2_INSTANCE_LAUNCH",
                                "autoscaling:EC2_INSTANCE_LAUNCH_ERROR",
                                "autoscaling:EC2_INSTANCE_TERMINATE",
                                "autoscaling:EC2_INSTANCE_TERMINATE_ERROR",
                                "autoscaling:TEST_NOTIFICATION"
                            ],
    'TopicARN' => 'arn:aws:sns:us-east-1:372100151213:MyTopic', // REQUIRED
]);

echo "All AutoScaling Process has been done";