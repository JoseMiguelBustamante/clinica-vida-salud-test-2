<?php

declare(strict_types=1);

namespace App\Modules\Appointments\Http\Requests;

use App\Modules\Identity\PermissionCatalog;
use Illuminate\Foundation\Http\FormRequest;

final class StartConsultationPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $codes = $this->attributes->get('identity_permissions', []);

        return in_array(PermissionCatalog::PAY_CONSULTATIONS, $codes, true);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [];
    }
}
