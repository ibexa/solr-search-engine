<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */

namespace Ibexa\Solr\Query\Common\CriterionVisitor;

use Ibexa\Contracts\Core\Repository\Values\Content\Query\Criterion;
use Ibexa\Contracts\Core\Repository\Values\Content\Query\Criterion\Operator;
use Ibexa\Contracts\Core\Repository\Values\Content\Query\CriterionInterface;
use Ibexa\Contracts\Solr\Query\CriterionVisitor;

/**
 * Visits the ContentId criterion.
 */
class ContentIdIn extends CriterionVisitor
{
    /**
     * Check if visitor is applicable to current criterion.
     *
     * @return bool
     */
    public function canVisit(CriterionInterface $criterion)
    {
        return
            $criterion instanceof Criterion\ContentId &&
            (
                ($criterion->operator ?: Operator::IN) === Operator::IN ||
                $criterion->operator === Operator::EQ
            );
    }

    /**
     * Map field value to a proper Solr representation.
     *
     * @param \Ibexa\Contracts\Core\Repository\Values\Content\Query\Criterion\ContentId $criterion
     * @param \Ibexa\Contracts\Solr\Query\CriterionVisitor $subVisitor
     *
     * @return string
     */
    public function visit(CriterionInterface $criterion, CriterionVisitor $subVisitor = null)
    {
        return '(' .
            implode(
                ' OR ',
                array_map(
                    static function ($value) {
                        return 'content_id_id:"' . $value . '"';
                    },
                    $criterion->value
                )
            ) .
            ')';
    }
}
