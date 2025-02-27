<?php

namespace Mautic\UserBundle\Controller\Api;

use Mautic\ApiBundle\Controller\CommonApiController;
use Symfony\Component\HttpKernel\Event\ControllerEvent;

class RoleApiController extends CommonApiController
{
    /**
     * {@inheritdoc}
     */
    public function initialize(ControllerEvent $event)
    {
        $this->model            = $this->getModel('user.role');
        $this->entityClass      = 'Mautic\UserBundle\Entity\Role';
        $this->entityNameOne    = 'role';
        $this->entityNameMulti  = 'roles';
        $this->serializerGroups = ['roleDetails', 'publishDetails'];

        parent::initialize($event);
    }

    /**
     * {@inheritdoc}
     *
     * @param \Mautic\LeadBundle\Entity\Lead &$entity
     * @param                                $parameters
     * @param                                $form
     * @param string                         $action
     */
    protected function preSaveEntity(&$entity, $form, $parameters, $action = 'edit')
    {
        if (isset($parameters['rawPermissions'])) {
            $this->model->setRolePermissions($entity, $parameters['rawPermissions']);
        }
    }
}
