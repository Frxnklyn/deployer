<?php
namespace axenox\Deployer\DataTypes;

use exface\Core\DataTypes\StringDataType;

/**
 * Read-only model-upgrade compatibility for stored legacy datatype references.
 * New metadata uses Core StringDataType; this class no longer offers recipe choices.
 *
 * @author Andrej Kabachnik
 *
 */
class BuildRecipeDataType extends StringDataType
{
}