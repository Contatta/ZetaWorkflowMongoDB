<?php

require_once "ezc/Base/base.php";
spl_autoload_register(array("ezcBase", "autoload"));



require_once "MongoWorkflow/DefinitionStorage.php";
require_once "MongoWorkflow/MongoClient.php";
require_once "MongoWorkflow/Execution.php";
require_once "MongoWorkflow/Workflow.php";


use MongoWorkflow\DefinitionStorage;
use MongoWorkflow\Execution;
use MongoWorkflow\MongoClient;
use MongoWorkflow\Workflow;

class WorkflowTestActivity implements \ezcWorkflowServiceObject
{
    public function __toString()
    {
        return 'testActivity';
    }

    public function execute(\ezcWorkflowExecution $execution)
    {
        //$activities = $execution->getVariable('rememberActivities');
        //$activity = array_rand($activities);

        //echo "Perform least objectionable activity: " . $activity . "\n";
    }
}

class WorkflowTest extends PHPUnit_Framework_TestCase
{

    public function testCreateWorkflow()
    {


        $workflow = new Workflow('wf_1');
        $activity1 = $this->createActivity();
        $activity2 = $this->createActivity();

        $workflow->startNode->addOutNode($activity1);
        $activity1->addOutNode($activity2);
        $activity2->addOutNode($workflow->endNode);


        $client = new MongoClient();
        $storage = new DefinitionStorage($client);
        $storage->save($workflow);

        //Load again

        $aWorkflow = $storage->loadByName('wf_1');

        $execution = new Execution($client, null);
        $execution->workflow = $aWorkflow;
        $executionId = $execution->start();


    }




    protected function createActivity()
    {
        $workflowActivity = new \ezcWorkflowNodeAction(
                    array( 'class' => 'WorkflowTestActivity',
                       ));
        return $workflowActivity;
    }
}
