<?php
namespace MongoWorkflow;

class Execution extends \ezcWorkflowExecution
{

    protected $client;
    protected $parentId;

    public function __construct ($client, $executionId = null )
     {
         $this->client = $client;
         $this->properties['definitionStorage'] = new DefinitionStorage( $client );

         if ( $executionId !== null )
         {
             $this->loadExecution( $executionId );
         }
     }

     protected function loadExecution($executionId)
     {
         $body = $this->client->request('GET', '/'. $executionId);

         if (!isset($body['_id']) || !isset($body['_rev'])) {
             throw CouchWorkflow_InvalidWorkflowException::missingIdAndRevision();
         }

         if (!isset($body['type']) || $body['type'] != "zeta_workflow_execution") {
             throw CouchWorkflow_InvalidWorkflowException::notExecutionDocument($executionId);
         }

         $this->id = $body['_id'];
         $this->revision = $body['_rev'];
         $this->workflow = $this->definitionStorage->loadById($body['workflow_id']);
         $this->parentId = $body['execution_parent'];
         $this->variables = $body['variables'];
         $this->threads = $body['threads'];
         $this->nextThreadId = $body['nextThreadId'];

         if (isset($body['waitingFor'])) {
             foreach ($body['waitingFor'] AS $variable => $data) {
                 $this->waitingFor[$variable] = array(
                     'node'      => $data['node'],
                     'condition' => unserialize($data['condition']),
                 );
             }
         }

         foreach ($this->workflow->nodes AS $node) {
             $nodeId = $node->getId();

             if ( isset( $body['states'][$nodeId] ) )
             {
                 $node->setActivationState( ezcWorkflowNode::WAITING_FOR_EXECUTION );
                 $node->setThreadId( $body['states'][$nodeId]['threadId'] );
                 $node->setState( $body['states'][$nodeId]['data'], null );
                 $node->setActivatedFrom( $body['states'][$nodeId]['activatedFrom'] );

                 $this->activate( $node, false );
             }
         }
     }

     /**
      * Start workflow execution.
      *
      * @param  int $parentId
      * @throws ezcDbException
      */
     protected function doStart( $parentId )
     {
         $this->parentId = $parentId;
         $this->saveMongo();
     }

     /**
      * Suspend workflow execution.
      *
      * @throws ezcDbException
      */
     protected function doSuspend()
     {
         $this->saveMongo(time(), true);
     }

     protected function doResume()
     {
         // NOOP
     }

     protected function doEnd()
     {
         //$body = $this->client->request('DELETE', '/' . $this->id, null, array('rev' => $this->revision));
         $this->client->getDb()->execution->remove(array('_id' => $this->id));

     }

     private function saveMongo($timeSuspended = null, $isSuspended = false)
     {
         $waitingForData = array();
         foreach ($this->waitingFor AS $variableName => $waitingFor) {
             $waitingForData[$variableName] = array(
                 'node' => $waitingFor['node'],
                 'condition' => serialize($waitingFor['condition']),
                 'rootClass' => get_class($waitingFor['condition']),
             );

             switch ($waitingForData[$variableName]['rootClass']) {
                 case 'ezcWorkflowConditionInArray':
                     $waitingForData[$variableName]['values'] = $waitingFor['condition']->getValue();
                     break;
                 case 'ezcWorkflowConditionIsEqual':
                 case 'ezcWorkflowConditionIsEqualOrGreaterThan':
                 case 'ezcWorkflowConditionIsEqualOrLessThan':
                 case 'ezcWorkflowConditionIsGreaterThan':
                 case 'ezcWorkflowConditionIsLessThan':
                 case 'ezcWorkflowConditionIsNotEqual':
                     $waitingForData[$variableName]['value'] = $waitingFor['condition']->getValue();
                     break;
             }
         }

         $states = array();
         if ($isSuspended) {
             // Save the state of all currently activated nodes if the execution is suspended.
             foreach ( $this->activatedNodes as $node ) {
                 $state = array(
                     'data' => $node->getState(),
                     'activatedFrom' => $node->getActivatedFrom(),
                     'threadId' => $node->getThreadId(),
                 );
                 $states[$node->getId()] = $state;
             }
         }

         $data = array(
             'type' => 'zeta_workflow_execution',
             'workflow_id' => $this->workflow->id,
             'execution_parent' => $this->parentId,
             'execution_started' => time(),
             'execution_suspended' => $timeSuspended,
             'variables' => $this->variables,
             'waitingFor' => $waitingForData,
             'threads' => $this->threads,
             'nextThreadId' => $this->nextThreadId,
             'states' => $states,
         );

         if ($isSuspended) {
             $data['version'] = $this->revision;

             $this->client->getDb()->execution->insert($data);

             //$response = $this->client->request('PUT', '/' . $this->id, $data);
             $this->revision = $data['rev'];
         } else {

             $this->client->getDb()->execution->insert($data);


             //$response = $this->client->request('POST', '', $data);
             $this->id = $data['_id'];
             //$this->revision = $data['rev'];
         }
     }

     protected function doGetSubExecution($id = null)
     {
         return new CouchWorkflow_Execution($this->client, $id);
     }

     /// gah ugly copy paste
     public function start( $parentId = null )
     {
         if ( $this->workflow === null )
         {
             throw new ezcWorkflowExecutionException(
               'No workflow has been set up for execution.'
             );
         }

         $this->cancelled = false;
         $this->ended     = false;
         $this->resumed   = false;
         $this->suspended = false;

         $this->doStart( $parentId );
         $this->loadFromVariableHandlers();

         foreach ( $this->plugins as $plugin )
         {
             $plugin->afterExecutionStarted( $this );
         }

         // Start workflow execution by activating the start node.
         $this->workflow->startNode->activate( $this );

         // Continue workflow execution until there are no more
         // activated nodes.
         $this->execute();

         // Return execution ID if the workflow has been suspended.
         if ( $this->isSuspended() )
         {
             return $this->id;
         }
     }
}
