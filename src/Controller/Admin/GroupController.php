<?php
namespace Group\Controller\Admin;

use Group\Form\GroupForm;
use Omeka\Form\ConfirmForm;
use Omeka\Stdlib\Message;
use Zend\Http\Response;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\JsonModel;
use Zend\View\Model\ViewModel;

class GroupController extends AbstractActionController
{
    // TODO Search action.
    // public function searchAction()
    // {
    // }

    public function browseAction()
    {
        $this->setBrowseDefaults('name', 'asc');
        $response = $this->api()->search('groups', $this->params()->fromQuery());
        $this->paginator($response->getTotalResults(), $this->params()->fromQuery('page'));

        $groups = $response->getContent();
        $groupCount = $this->viewHelpers()->get('groupCount');
        $groupCount = $groupCount($groups);

        $view = new ViewModel;
        $view->setVariable('groups', $groups);
        $view->setVariable('groupCount', $groupCount);
        return $view;
    }

    public function showAction()
    {
        $response = $this->apiReadFromIdOrName();

        $view = new ViewModel;
        $entity = $response->getContent();
        $view->setVariable('group', $entity);
        $view->setVariable('resource', $entity);
        return $view;
    }

    public function showDetailsAction()
    {
        $response = $this->apiReadFromIdOrName();
        $group = $response->getContent();

        $groupCount = $this->viewHelpers()->get('groupCount');
        $groupCount = $groupCount($group);
        $groupCount = reset($groupCount);

        $view = new ViewModel;
        $view->setTerminal(true);
        $view->setVariable('resource', $group);
        $view->setVariable('groupCount', $groupCount);
        return $view;
    }

    public function deleteAction()
    {
        if ($this->getRequest()->isPost()) {
            $form = $this->getForm(ConfirmForm::class);
            $form->setData($this->getRequest()->getPost());
            if ($form->isValid()) {
                $entity = $this->apiReadFromIdOrName()->getContent();
                $response = $this->api($form)->delete('groups', $entity->id());
                if ($response) {
                    $this->messenger()->addSuccess('Group successfully deleted.'); // @translate
                }
            } else {
                $this->messenger()->addFormErrors($form);
            }
        }
        return $this->redirect()->toRoute('admin/group');
    }

    public function deleteConfirmAction()
    {
        $response = $this->apiReadFromIdOrName();
        $group = $response->getContent();

        $groupCount = $this->viewHelpers()->get('groupCount');
        $groupCount = $groupCount($group);
        $groupCount = reset($groupCount);

        $view = new ViewModel;
        $view->setTerminal(true);
        $view->setTemplate('common/delete-confirm-details');
        $view->setVariable('group', $group);
        $view->setVariable('groupCount', $groupCount);
        $view->setVariable('resource', $group);
        $view->setVariable('resourceLabel', 'group');
        $view->setVariable('partialPath', 'group/admin/group/show-details');
        return $view;
    }

    public function batchDeleteConfirmAction()
    {
        $form = $this->getForm(ConfirmForm::class);
        $routeAction = $this->params()->fromQuery('all') ? 'batch-delete-all' : 'batch-delete';
        $form->setAttribute('action', $this->url()->fromRoute(null, ['action' => $routeAction], true));
        $form->setButtonLabel('Confirm delete'); // @translate
        $form->setAttribute('id', 'batch-delete-confirm');
        $form->setAttribute('class', $routeAction);

        $view = new ViewModel;
        $view->setTerminal(true);
        $view->setVariable('form', $form);
        return $view;
    }

    public function batchDeleteAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute(null, ['action' => 'browse'], true);
        }

        $resourceIds = $this->params()->fromPost('resource_ids', []);
        if (!$resourceIds) {
            $this->messenger()->addError('You must select at least one group to batch delete.'); // @translate
            return $this->redirect()->toRoute(null, ['action' => 'browse'], true);
        }

        $form = $this->getForm(ConfirmForm::class);
        $form->setData($this->getRequest()->getPost());
        if ($form->isValid()) {
            $response = $this->api($form)->batchDelete('groups', $resourceIds, [], ['continueOnError' => true]);
            if ($response) {
                $this->messenger()->addSuccess('Groups successfully deleted.'); // @translate
            }
        } else {
            $this->messenger()->addFormErrors($form);
        }
        return $this->redirect()->toRoute(null, ['action' => 'browse'], true);
    }

    public function batchDeleteAllAction()
    {
        // TODO Support batch delete all.
        $this->messenger()->addError('Delete of all groups is not supported currently.'); // @translate
    }

    public function addAction()
    {
        $form = $this->getForm(GroupForm::class);
        $form->setAttribute('action', $this->url()->fromRoute(null, [], true));
        $form->setAttribute('enctype', 'multipart/form-data');
        $form->setAttribute('id', 'add-group');
        if ($this->getRequest()->isPost()) {
            $data = $this->params()->fromPost();
            $form->setData($data);
            if ($form->isValid()) {
                $response = $this->api($form)->create('groups', $data);
                if ($response) {
                    $message = new Message(
                        'Group successfully created. %s', // @translate
                        sprintf(
                            '<a href="%s">%s</a>',
                            htmlspecialchars($this->url()->fromRoute(null, [], true)),
                            $this->translate('Add another group?') // @translate
                    ));
                    $message->setEscapeHtml(false);
                    $this->messenger()->addSuccess($message);
                    return $this->redirect()->toUrl($response->getContent()->url());
                }
            } else {
                $this->messenger()->addFormErrors($form);
            }
        }

        $view = new ViewModel;
        $view->setVariable('form', $form);
        return $view;
    }

    public function updateAction()
    {
        $response = $this->apiReadFromIdOrName();
        $group = $response->getContent();
        $id = $group->id();
        $name = $this->params()->fromPost('text');

        $data = [];
        $data['o:name'] = $name;
        $response = $this->api()->update('groups', $id, $data, [], ['isPartial' => true]);
        if (!$response) {
            return $this->jsonErrorName();
        }

        $group = $response->getContent();
        $escape = $this->viewHelpers()->get('escapeHtml');
        return new JsonModel([
            'content' => [
                'text' => $group->name(),
                'escaped' => $escape($group->name()),
                'urls' => [
                    'update' => $group->url('update'),
                    'show_details' => $group->url('show-details'),
                    'delete_confirm' => $group->url('delete-confirm'),
                    'users' => $group->urlEntities('user'),
                    'item_sets' => $group->urlEntities('item-set'),
                    'items' => $group->urlEntities('item'),
                    'media' => $group->urlEntities('media'),
                ],
            ],
        ]);
    }

    protected function jsonErrorEmpty()
    {
        $response = $this->getResponse();
        $response->setStatusCode(Response::STATUS_CODE_400);
        return new JsonModel(['error' => 'No groups submitted.']); // @translate
    }

    protected function jsonErrorName()
    {
        $response = $this->getResponse();
        $response->setStatusCode(Response::STATUS_CODE_400);
        return new JsonModel(['error' => 'This group is invalid: it is a duplicate or it contains forbidden characters.']); // @translate
    }

    protected function jsonErrorUnauthorized()
    {
        $response = $this->getResponse();
        $response->setStatusCode(Response::STATUS_CODE_403);
        return new JsonModel(['error' => 'Unauthorized access.']); // @translate
    }

    protected function jsonErrorNotFound()
    {
        $response = $this->getResponse();
        $response->setStatusCode(Response::STATUS_CODE_404);
        return new JsonModel(['error' => 'Group not found.']); // @translate
    }

    protected function jsonErrorUpdate()
    {
        $response = $this->getResponse();
        $response->setStatusCode(Response::STATUS_CODE_500);
        return new JsonModel(['error' => 'An internal error occurred.']); // @translate
    }

    protected function apiReadFromIdOrName()
    {
        $id = $this->params('id');
        if ($id) {
            $response = $this->api()->read('groups', $id);
        } else {
            $name = $this->params('name');
            $response = $this->api()->search('groups', [
                'name' => $this->params('name'),
                'limit' => 1,
            ]);
            $content = $response->getContent();
            if (empty($content)) {
                throw new \Omeka\Api\Exception\NotFoundException(new Message(
                    '%s entity with criteria {"%s":"%s"} not found.', // @translate
                    'Group\Entity\Group', 'name', $name));
            }
            $content = is_array($content) && count($content) ? $content[0] : null;
            $response->setContent($content);
        }
        return $response;
    }
}
