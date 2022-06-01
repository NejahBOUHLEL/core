<?php
/*
Gibbon, Flexible & Open School System
Copyright (C) 2010, Ross Parker

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program. If not, see <http://www.gnu.org/licenses/>.
*/

namespace Gibbon\Forms\Builder\Process;

use Gibbon\Data\UsernameGenerator;
use Gibbon\Domain\User\FamilyAdultGateway;
use Gibbon\Domain\User\UserGateway;
use Gibbon\Domain\User\PersonalDocumentGateway;
use Gibbon\Domain\System\CustomFieldGateway;
use Gibbon\Forms\Builder\FormBuilderInterface;
use Gibbon\Forms\Builder\Process\CreateStudent;
use Gibbon\Forms\Builder\Storage\FormDataInterface;
use Gibbon\Forms\Builder\View\CreateParentsView;

class CreateParents extends CreateStudent implements ViewableProcess
{
    protected $requiredFields = ['parent1preferredName', 'parent1surname', 'parent1relationship'];

    protected $familyAdultGateway;

    public function __construct(UserGateway $userGateway, UsernameGenerator $usernameGenerator, CustomFieldGateway $customFieldGateway, PersonalDocumentGateway $personalDocumentGateway, FamilyAdultGateway $familyAdultGateway)
    {
        $this->familyAdultGateway = $familyAdultGateway;

        parent::__construct($userGateway, $usernameGenerator, $customFieldGateway, $personalDocumentGateway);
    }

    public function getViewClass() : string
    {
        return CreateParentsView::class;
    }

    public function isEnabled(FormBuilderInterface $builder)
    {
        return $builder->getConfig('createParents') == 'Y';
    }

    public function process(FormBuilderInterface $builder, FormDataInterface $formData)
    {
        // Create Parent 1
        if (!$formData->has('gibbonPersonIDParent1') && $formData->hasAll(['parent1surname', 'parent1preferredName'])) {
            $this->createParentAccount($builder, $formData, '1');
        }

        // Update new or existing Parent 1
        if ($formData->has('gibbonPersonIDParent1')) {
            $this->updateParentRole($formData, '1');
            $this->addParentToFamily($formData, '1');
        }

        // Create Parent 2
        if (!$formData->has('gibbonPersonIDParent1') && !$formData->has('gibbonPersonIDParent2') && $formData->hasAll(['parent2surname', 'parent2preferredName'])) {
            $this->createParentAccount($builder, $formData, '2');
        }

        // Update new or existing Parent 2
        if ($formData->has('gibbonPersonIDParent2')) {
            $this->updateParentRole($formData, '2');
            $this->addParentToFamily($formData, '2');
        }

        $this->setResult(true);
    }

    public function rollback(FormBuilderInterface $builder, FormDataInterface $formData)
    {
        if (!$formData->has('gibbonPersonIDParent1')) return;

        // Remove the relationships, they are always new
        $this->familyAdultGateway->deleteFamilyRelationship($formData->get('gibbonFamilyID'), $formData->get('gibbonPersonIDParent1'), $formData->get('gibbonPersonIDStudent'));
        $this->familyAdultGateway->deleteFamilyRelationship($formData->get('gibbonFamilyID'), $formData->get('gibbonPersonIDParent2'), $formData->get('gibbonPersonIDStudent'));

        // Only disconnect family if they were connected during this process
        if ($formData->has('parent1adultAdded')) {
            $this->familyAdultGateway->deleteFamilyAdult($formData->get('gibbonFamilyID'), $formData->get('gibbonPersonIDParent1'));
        }

        if ($formData->has('parent2adultAdded')) {
            $this->familyAdultGateway->deleteFamilyAdult($formData->get('gibbonFamilyID'), $formData->get('gibbonPersonIDParent2'));
        }

        // Only remove roles if they were added during this process
        if ($formData->has('parent1roleChanged')) {
            $this->userGateway->removeRoleFromUser($formData->get('gibbonPersonIDParent1'), '004');
        }

        if ($formData->has('parent2roleChanged')) {
            $this->userGateway->removeRoleFromUser($formData->get('gibbonPersonIDParent2'), '004');
        }
        
        // Only remove users if they were created during this process
        if ($formData->has('parent1created')) {
            $this->userGateway->delete($formData->get('gibbonPersonIDParent1'));
            $formData->set('gibbonPersonIDParent1', null);
        }

        if ($formData->has('parent2created')) {
            $this->userGateway->delete($formData->get('gibbonPersonIDParent2'));
            $formData->set('gibbonPersonIDParent2', null);
        }
    }

    protected function createParentAccount(FormBuilderInterface $builder, FormDataInterface $formData, $i)
    {
        // Generate user details
        $this->generateUsername($formData, '004', "parent{$i}");
        $this->generatePassword($formData, "parent{$i}");

        // Set and assign default values
        $this->setStatus($formData, "parent{$i}");
        $this->setDefaults($formData, "parent{$i}");
        $this->setCustomFields($formData, "parent{$i}");

        // Create and store the new parent account
        $gibbonPersonID = $this->userGateway->insert($this->getUserData($formData, '004', "parent{$i}"));
        $formData->set("gibbonPersonIDParent{$i}", $gibbonPersonID);
        $formData->set("parent{$i}created", !empty($gibbonPersonID));

        // Update existing data
        $this->transferPersonalDocuments($builder, $formData, $gibbonPersonID);
    }

    protected function updateParentRole(FormDataInterface $formData, $i)
    {
        $updated = $this->userGateway->addRoleToUser($formData->get("gibbonPersonIDParent{$i}"), '004');
        $formData->set("parent{$i}roleChanged", $updated);
    }

    protected function addParentToFamily(FormDataInterface $formData, $i)
    {
        if (!$formData->hasAll(["gibbonFamilyID", "parent{$i}relationship", "gibbonPersonIDParent{$i}", "gibbonPersonIDStudent"])) {
            return;
        }

        $existing = $this->familyAdultGateway->selectBy(['gibbonFamilyID' => $formData->get('gibbonFamilyID'), 'gibbonPersonID' => $formData->get("gibbonPersonIDParent{$i}")])->fetch();

        if (empty($existing)) {
            $gibbonFamilyAdultID = $this->familyAdultGateway->insert([
                'gibbonFamilyID'  => $formData->get('gibbonFamilyID'),
                'gibbonPersonID'  => $formData->get("gibbonPersonIDParent{$i}"),
                'childDataAccess' => 'Y',
                'contactPriority' => $i,
                'contactCall'     => 'Y',
                'contactSMS'      => 'Y',
                'contactEmail'    => 'Y',
                'contactMail'     => 'Y',
            ]);
            $formData->set("parent{$i}adultAdded", !empty($gibbonFamilyAdultID));
        } else {
            $gibbonFamilyAdultID = $existing['gibbonFamilyAdultID'];
        }

        $this->familyAdultGateway->insertFamilyRelationship($formData->get('gibbonFamilyID'), $formData->get("gibbonPersonIDParent{$i}"), $formData->get('gibbonPersonIDStudent'), $formData->get("parent{$i}relationship"));

        $formData->set("parent{$i}adultLinked", !empty($gibbonFamilyAdultID));
    }
}