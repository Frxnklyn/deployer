<?php
namespace axenox\Deployer\Common\Deployment;

use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;

/**
 * Updates action-owned deployment records from normal runs and later HTTP reports.
 *
 * Optional expected values are added as row filters, so callers can make a
 * lifecycle update conditional on the state previously read from persistence.
 */
final class DeploymentRecordWriter
{
    /**
     * Applies attribute values to one sheet row and persists the filtered update.
     *
     * @param DataSheetInterface $sheet Deployment data sheet containing the target row.
     * @param array<string, mixed> $values Attribute aliases and their replacement values.
     * @param int $row Zero-based row to update.
     * @param array<string, mixed>|null $expected Optional attribute values required for the update.
     * @return int Number of rows updated by the data source.
     */
    public static function update(DataSheetInterface $sheet, array $values, int $row = 0, ?array $expected = null): int
    {
        if ($expected !== null) {
            foreach ($expected as $attribute => $value) {
                if ($value === null) {
                    $sheet->getFilters()->addConditionForAttributeIsNull($attribute);
                } else {
                    $sheet->getFilters()->addConditionFromString($attribute, $value, ComparatorDataType::EQUALS);
                }
            }
        }
        foreach ($values as $attribute => $value) {
            $sheet->setCellValue($attribute, $row, $value);
        }
        return $sheet->dataUpdate(false);
    }
}
