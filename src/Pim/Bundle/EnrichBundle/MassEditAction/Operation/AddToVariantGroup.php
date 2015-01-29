<?php

namespace Pim\Bundle\EnrichBundle\MassEditAction\Operation;

use Akeneo\Component\StorageUtils\Saver\BulkSaverInterface;
use Pim\Bundle\CatalogBundle\Model\GroupInterface;
use Pim\Bundle\CatalogBundle\Model\ProductInterface;
use Pim\Bundle\CatalogBundle\Repository\GroupRepositoryInterface;
use Pim\Bundle\CatalogBundle\Repository\ProductMassActionRepositoryInterface;
use Pim\Bundle\CatalogBundle\Updater\ProductTemplateUpdaterInterface;
use Symfony\Component\Validator\ValidatorInterface;

/**
 * Operation to add products to variant groups
 *
 * @author    Julien Sanchez <julien@akeneo.com>
 * @copyright 2015 Akeneo SAS (http://www.akeneo.com)
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 *
 * TODO missing specs
 * TODO missing behat to assign to a group or a variant group
 */
class AddToVariantGroup extends ProductMassEditOperation
{
    /** @var array */
    protected $skippedObjects = [];

    /** @var GroupRepository */
    protected $groupRepository;

    /** @var ProductTemplateUpdaterInterface */
    protected $templateUpdater;

    /** @var ValidatorInterface */
    protected $validator;

    /** @var GroupInterface */
    protected $group;

    /** @var string[] */
    protected $warningMessages = null;

    /** @var array  */
    protected $validVariantGroups = [];

    /**
     * @param GroupRepositoryInterface              $groupRepository
     * @param BulkSaverInterface                    $productSaver
     * @param ProductTemplateUpdaterInterface       $templateUpdater
     * @param ValidatorInterface                    $validator
     * @param ProductMassActionRepositoryInterface  $prodMassActionRepo
     */
    public function __construct(
        GroupRepositoryInterface $groupRepository,
        BulkSaverInterface $productSaver,
        ProductTemplateUpdaterInterface $templateUpdater,
        ValidatorInterface $validator,
        ProductMassActionRepositoryInterface $prodMassActionRepo
    ) {
        parent::__construct($productSaver);

        $this->groupRepository = $groupRepository;
        $this->templateUpdater = $templateUpdater;
        $this->validator = $validator;
        $this->productMassActionRepo = $prodMassActionRepo;
    }

    /**
     * {@inheritdoc}
     */
    public function setObjectsToMassEdit(array $objects)
    {
        $this->objects        = [];
        $this->skippedObjects = [];

        foreach ($objects as $object) {
            $violations = $this->validator->validate($object, ['pim_catalog_variant_group']);

            if ($object instanceof ProductInterface &&
                null === $object->getVariantGroup() &&
                count($violations) === 0
            ) {
                $this->objects[] = $object;
            } else {
                $this->skippedObjects[] = $object;
            }
        }

        return $this;
    }

    /**
     * Set group
     *
     * @param GroupInterface $group
     */
    public function setGroup(GroupInterface $group)
    {
        $this->group = $group;
    }

    /**
     * Get group
     *
     * @return array
     */
    public function getGroup()
    {
        return $this->group;
    }

    /**
     * {@inheritdoc}
     */
    public function getFormOptions()
    {
        return [
            'groups' => $this->getVariantGroupsWithCommonAttributes()
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getFormType()
    {
        return 'pim_enrich_mass_add_to_variant_group';
    }

    /**
     * {@inheritdoc}
     */
    public function perform()
    {
        parent::perform();

        if (null !== $this->group->getProductTemplate()) {
            $this->templateUpdater->update($this->group->getProductTemplate(), $this->objects);
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function doPerform(ProductInterface $product)
    {
        if (null === $product->getVariantGroup()) {
            $this->group->addProduct($product);
        }
    }

    /**
     * Get warning messages
     *
     * @return string[]
     */
    public function getWarningMessages()
    {
        if (null === $this->warningMessages) {
            $this->warningMessages = $this->generateWarningMessages($this->objects);
        }

        return $this->warningMessages;
    }

    /**
     * Get warning messages to display during the mass edit action
     *
     * @return string[]
     */
    protected function generateWarningMessages()
    {
        $messages = [];

        if ($this->hasNoVariantGroupWarning()) {
            $messages[] = [
                'key'     => 'pim_enrich.mass_edit_action.add-to-variant-group.no_variant_group',
                'options' => []
            ];
        } elseif ($this->hasNoValidVariantGroupWarning()) {
            $messages[] = [
                'key'     => 'pim_enrich.mass_edit_action.add-to-variant-group.no_valid_variant_group',
                'options' => []
            ];
        }

        $invalidProducts = $this->getInvalidProductWarningInfos();
        if ($invalidProducts) {
            $messages[] = [
                'key'     =>
                    'pim_enrich.mass_edit_action.add-to-variant-group.already_in_variant_group_or_not_valid',
                'options' =>
                    ['%products%' => implode(', ', $invalidProducts)]
            ];
        }

        $skippedVariantGroups = $this->getSkippedVariantGroupWarningInfos();
        if ($skippedVariantGroups) {
            $messages[] = [
                'key'     => 'pim_enrich.mass_edit_action.add-to-variant-group.some_variant_groups_are_skipped',
                'options' => ['%groups%' => implode(', ', $skippedVariantGroups)]
            ];
        }

        return $messages;
    }

    /**
     * Has a warning if there is no variant group
     *
     * @return bool
     */
    protected function hasNoVariantGroupWarning()
    {
        $variantGroups = $this->groupRepository->getAllVariantGroups();

        return count($variantGroups) === 0;
    }

    /**
     * Has a warning if there is no valid variant group.
     *
     * @return bool
     */
    protected function hasNoValidVariantGroupWarning()
    {
        return count($this->validVariantGroups) === 0;
    }

    /**
     * Get warning information if there is any invalid product.
     * Return all invalid product identifiers in an array, or an empty array if no invalid product.
     *
     * @return array
     */
    protected function getInvalidProductWarningInfos()
    {
        $invalidProducts = [];

        foreach ($this->skippedObjects as $product) {
            if ($product instanceof ProductInterface) {
                $invalidProducts[] = $product->getIdentifier();
            }
        }

        return $invalidProducts;
    }

    /**
     * Get warning information if there is any skipped variant group (no common attribute with products).
     * Return all skipped variant groups with their label and code, or an empty array if no variant group skipped.
     *
     * @return array
     */
    protected function getSkippedVariantGroupWarningInfos()
    {
        $variantGroups = $this->groupRepository->getAllVariantGroups();
        $invalidVariantGroups = array_diff($variantGroups, $this->validVariantGroups);

        $skippedVariantGroups = array_map(function ($variantGroup) {
            return sprintf('%s [%s]', $variantGroup->getLabel(), $variantGroup->getCode());
        }, $invalidVariantGroups);

        return $skippedVariantGroups;
    }

    /**
     * Get and returns all variant groups with common attributes with selected products
     *
     * @return array
     */
    protected function getVariantGroupsWithCommonAttributes()
    {
        if ($this->objects) {
            $productIds = array_map(function ($product) {
                return $product->getId();
            }, $this->objects);

            $commonAttributeIds = $this->productMassActionRepo->findCommonAttributeIds($productIds);
            $this->validVariantGroups = $this->groupRepository->getVariantGroupsByAttributes($commonAttributeIds);
        }

        return $this->validVariantGroups;
    }
}
