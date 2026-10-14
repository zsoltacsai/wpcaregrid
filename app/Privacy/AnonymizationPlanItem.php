<?php

namespace App\Privacy;

final readonly class AnonymizationPlanItem
{
    public function __construct(
        public string $category,
        public string $field,
        public AnonymizationAction $action,
        public string $approvalState,
    ) {}

    /** @return array{category:string,field:string,action:string,approval_state:string} */
    public function toArray(): array
    {
        return [
            'category' => $this->category,
            'field' => $this->field,
            'action' => $this->action->value,
            'approval_state' => $this->approvalState,
        ];
    }
}
