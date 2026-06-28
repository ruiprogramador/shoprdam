<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class LangFilesTranslationSeeder extends Seeder
{
    /**
     * Flatten a nested array into dot-notation keys.
     * e.g. ['between' => ['array' => 'msg']] => ['between.array' => 'msg']
     */
    private function flatten(array $array, string $prefix = ''): array
    {
        $result = [];
        foreach ($array as $key => $value) {
            $fullKey = $prefix !== '' ? $prefix . '.' . $key : $key;
            if (is_array($value)) {
                $result = array_merge($result, $this->flatten($value, $fullKey));
            } else {
                $result[$fullKey] = $value;
            }
        }
        return $result;
    }

    /**
     * Build a human-readable label from a dot-notation key.
     * e.g. 'kyc.submitted' => 'Kyc Submitted'
     */
    private function makeLabel(string $key): string
    {
        $parts = explode('.', $key);
        return implode(' ', array_map(fn($p) => ucfirst(str_replace('_', ' ', $p)), $parts));
    }

    /**
     * Return the Portuguese translations for every key.
     * Keys mirror the dot-notation produced by flatten().
     */
    private function ptTranslations(): array
    {
        return [
            // ── auth ─────────────────────────────────────────────────────────────
            'auth.failed'   => 'Estas credenciais não correspondem aos nossos registos.',
            'auth.password' => 'A palavra-passe fornecida está incorreta.',
            'auth.throttle' => 'Demasiadas tentativas de login. Por favor tente novamente em :seconds segundos.',

            // ── passwords ─────────────────────────────────────────────────────────
            'passwords.reset'     => 'A sua palavra-passe foi redefinida.',
            'passwords.sent'      => 'Enviámos por e-mail o link para redefinir a sua palavra-passe.',
            'passwords.throttled' => 'Por favor aguarde antes de tentar novamente.',
            'passwords.token'     => 'Este token de redefinição de palavra-passe é inválido.',
            'passwords.user'      => 'Não conseguimos encontrar um utilizador com esse endereço de e-mail.',

            // ── pagination ────────────────────────────────────────────────────────
            'pagination.previous' => '&laquo; Anterior',
            'pagination.next'     => 'Próximo &raquo;',

            // ── kyc ───────────────────────────────────────────────────────────────
            'kyc.not_found'    => 'O registo KYC não foi encontrado.',
            'kyc.submitted'    => 'O seu KYC foi submetido com sucesso e está pendente de revisão.',
            'kyc.updated'      => 'O seu KYC foi atualizado com sucesso.',
            'kyc.approved'     => 'O seu KYC foi aprovado com sucesso.',
            'kyc.rejected'     => 'O seu KYC foi rejeitado.',
            'kyc.cannot_submit'=> 'Não pode submeter um novo KYC neste momento.',
            'kyc.cannot_edit'  => 'Não pode editar o seu KYC neste momento.',

            // ── notifications.kyc ─────────────────────────────────────────────────
            'notifications.kyc.salutation'          => 'Com os melhores cumprimentos, :app_name',
            'notifications.kyc.submitted.subject'   => 'Nova Submissão KYC',
            'notifications.kyc.submitted.message'   => 'O vendedor :name submeteu um novo KYC para revisão.',
            'notifications.kyc.updated.subject'     => 'KYC Atualizado',
            'notifications.kyc.updated.message'     => 'O vendedor :name atualizou o seu KYC e aguarda revisão.',
            'notifications.kyc.approved.subject'    => 'O seu KYC foi aprovado',
            'notifications.kyc.approved.greeting'   => 'Olá, :name!',
            'notifications.kyc.approved.message'    => 'O seu processo de verificação de identidade (KYC) foi aprovado com sucesso.',
            'notifications.kyc.approved.expiration' => 'A sua verificação é válida até :date.',
            'notifications.kyc.approved.action'     => 'Ir para o Dashboard',
            'notifications.kyc.rejected.subject'    => 'O seu KYC foi rejeitado',
            'notifications.kyc.rejected.greeting'   => 'Olá, :name!',
            'notifications.kyc.rejected.message'    => 'O seu processo de verificação de identidade (KYC) foi rejeitado.',
            'notifications.kyc.rejected.reason'     => 'Motivo: :reason',
            'notifications.kyc.rejected.warning'    => 'Por favor corrija os problemas indicados e volte a submeter.',
            'notifications.kyc.rejected.action'     => 'Corrigir KYC',

            // ── validation ────────────────────────────────────────────────────────
            'validation.accepted'            => 'O campo :attribute deve ser aceite.',
            'validation.accepted_if'         => 'O campo :attribute deve ser aceite quando :other é :value.',
            'validation.active_url'          => 'O campo :attribute deve ser um URL válido.',
            'validation.after'               => 'O campo :attribute deve ser uma data posterior a :date.',
            'validation.after_or_equal'      => 'O campo :attribute deve ser uma data igual ou posterior a :date.',
            'validation.alpha'               => 'O campo :attribute deve conter apenas letras.',
            'validation.alpha_dash'          => 'O campo :attribute deve conter apenas letras, números, hífens e underscores.',
            'validation.alpha_num'           => 'O campo :attribute deve conter apenas letras e números.',
            'validation.any_of'              => 'O campo :attribute é inválido.',
            'validation.array'               => 'O campo :attribute deve ser um array.',
            'validation.ascii'               => 'O campo :attribute deve conter apenas caracteres alfanuméricos de byte único e símbolos.',
            'validation.before'              => 'O campo :attribute deve ser uma data anterior a :date.',
            'validation.before_or_equal'     => 'O campo :attribute deve ser uma data igual ou anterior a :date.',
            'validation.between.array'       => 'O campo :attribute deve ter entre :min e :max itens.',
            'validation.between.file'        => 'O campo :attribute deve ter entre :min e :max kilobytes.',
            'validation.between.numeric'     => 'O campo :attribute deve estar entre :min e :max.',
            'validation.between.string'      => 'O campo :attribute deve ter entre :min e :max caracteres.',
            'validation.boolean'             => 'O campo :attribute deve ser verdadeiro ou falso.',
            'validation.can'                 => 'O campo :attribute contém um valor não autorizado.',
            'validation.confirmed'           => 'A confirmação do campo :attribute não coincide.',
            'validation.contains'            => 'O campo :attribute está a faltar um valor obrigatório.',
            'validation.current_password'    => 'A palavra-passe está incorreta.',
            'validation.date'                => 'O campo :attribute deve ser uma data válida.',
            'validation.date_equals'         => 'O campo :attribute deve ser uma data igual a :date.',
            'validation.date_format'         => 'O campo :attribute deve corresponder ao formato :format.',
            'validation.decimal'             => 'O campo :attribute deve ter :decimal casas decimais.',
            'validation.declined'            => 'O campo :attribute deve ser recusado.',
            'validation.declined_if'         => 'O campo :attribute deve ser recusado quando :other é :value.',
            'validation.different'           => 'O campo :attribute e :other devem ser diferentes.',
            'validation.digits'              => 'O campo :attribute deve ter :digits dígitos.',
            'validation.digits_between'      => 'O campo :attribute deve ter entre :min e :max dígitos.',
            'validation.dimensions'          => 'O campo :attribute tem dimensões de imagem inválidas.',
            'validation.distinct'            => 'O campo :attribute tem um valor duplicado.',
            'validation.doesnt_contain'      => 'O campo :attribute não deve conter nenhum dos seguintes: :values.',
            'validation.doesnt_end_with'     => 'O campo :attribute não deve terminar com um dos seguintes: :values.',
            'validation.doesnt_start_with'   => 'O campo :attribute não deve começar com um dos seguintes: :values.',
            'validation.email'               => 'O campo :attribute deve ser um endereço de e-mail válido.',
            'validation.encoding'            => 'O campo :attribute deve estar codificado em :encoding.',
            'validation.ends_with'           => 'O campo :attribute deve terminar com um dos seguintes: :values.',
            'validation.enum'                => 'O :attribute selecionado é inválido.',
            'validation.exists'              => 'O :attribute selecionado é inválido.',
            'validation.extensions'          => 'O campo :attribute deve ter uma das seguintes extensões: :values.',
            'validation.file'                => 'O campo :attribute deve ser um ficheiro.',
            'validation.filled'              => 'O campo :attribute deve ter um valor.',
            'validation.gt.array'            => 'O campo :attribute deve ter mais do que :value itens.',
            'validation.gt.file'             => 'O campo :attribute deve ser maior do que :value kilobytes.',
            'validation.gt.numeric'          => 'O campo :attribute deve ser maior do que :value.',
            'validation.gt.string'           => 'O campo :attribute deve ter mais do que :value caracteres.',
            'validation.gte.array'           => 'O campo :attribute deve ter :value itens ou mais.',
            'validation.gte.file'            => 'O campo :attribute deve ser maior ou igual a :value kilobytes.',
            'validation.gte.numeric'         => 'O campo :attribute deve ser maior ou igual a :value.',
            'validation.gte.string'          => 'O campo :attribute deve ter :value caracteres ou mais.',
            'validation.hex_color'           => 'O campo :attribute deve ser uma cor hexadecimal válida.',
            'validation.image'               => 'O campo :attribute deve ser uma imagem.',
            'validation.in'                  => 'O :attribute selecionado é inválido.',
            'validation.in_array'            => 'O campo :attribute deve existir em :other.',
            'validation.in_array_keys'       => 'O campo :attribute deve conter pelo menos uma das seguintes chaves: :values.',
            'validation.integer'             => 'O campo :attribute deve ser um número inteiro.',
            'validation.ip'                  => 'O campo :attribute deve ser um endereço IP válido.',
            'validation.ipv4'                => 'O campo :attribute deve ser um endereço IPv4 válido.',
            'validation.ipv6'                => 'O campo :attribute deve ser um endereço IPv6 válido.',
            'validation.json'                => 'O campo :attribute deve ser uma string JSON válida.',
            'validation.list'                => 'O campo :attribute deve ser uma lista.',
            'validation.lowercase'           => 'O campo :attribute deve estar em minúsculas.',
            'validation.lt.array'            => 'O campo :attribute deve ter menos do que :value itens.',
            'validation.lt.file'             => 'O campo :attribute deve ser menor do que :value kilobytes.',
            'validation.lt.numeric'          => 'O campo :attribute deve ser menor do que :value.',
            'validation.lt.string'           => 'O campo :attribute deve ter menos do que :value caracteres.',
            'validation.lte.array'           => 'O campo :attribute não deve ter mais do que :value itens.',
            'validation.lte.file'            => 'O campo :attribute deve ser menor ou igual a :value kilobytes.',
            'validation.lte.numeric'         => 'O campo :attribute deve ser menor ou igual a :value.',
            'validation.lte.string'          => 'O campo :attribute deve ter no máximo :value caracteres.',
            'validation.mac_address'         => 'O campo :attribute deve ser um endereço MAC válido.',
            'validation.max.array'           => 'O campo :attribute não deve ter mais do que :max itens.',
            'validation.max.file'            => 'O campo :attribute não deve ser maior do que :max kilobytes.',
            'validation.max.numeric'         => 'O campo :attribute não deve ser maior do que :max.',
            'validation.max.string'          => 'O campo :attribute não deve ter mais do que :max caracteres.',
            'validation.max_digits'          => 'O campo :attribute não deve ter mais do que :max dígitos.',
            'validation.mimes'               => 'O campo :attribute deve ser um ficheiro do tipo: :values.',
            'validation.mimetypes'           => 'O campo :attribute deve ser um ficheiro do tipo: :values.',
            'validation.min.array'           => 'O campo :attribute deve ter pelo menos :min itens.',
            'validation.min.file'            => 'O campo :attribute deve ter pelo menos :min kilobytes.',
            'validation.min.numeric'         => 'O campo :attribute deve ser pelo menos :min.',
            'validation.min.string'          => 'O campo :attribute deve ter pelo menos :min caracteres.',
            'validation.min_digits'          => 'O campo :attribute deve ter pelo menos :min dígitos.',
            'validation.missing'             => 'O campo :attribute deve estar em falta.',
            'validation.missing_if'          => 'O campo :attribute deve estar em falta quando :other é :value.',
            'validation.missing_unless'      => 'O campo :attribute deve estar em falta a menos que :other seja :value.',
            'validation.missing_with'        => 'O campo :attribute deve estar em falta quando :values está presente.',
            'validation.missing_with_all'    => 'O campo :attribute deve estar em falta quando :values estão presentes.',
            'validation.multiple_of'         => 'O campo :attribute deve ser um múltiplo de :value.',
            'validation.not_in'              => 'O :attribute selecionado é inválido.',
            'validation.not_regex'           => 'O formato do campo :attribute é inválido.',
            'validation.numeric'             => 'O campo :attribute deve ser um número.',
            'validation.password.letters'    => 'O campo :attribute deve conter pelo menos uma letra.',
            'validation.password.mixed'      => 'O campo :attribute deve conter pelo menos uma letra maiúscula e uma minúscula.',
            'validation.password.numbers'    => 'O campo :attribute deve conter pelo menos um número.',
            'validation.password.symbols'    => 'O campo :attribute deve conter pelo menos um símbolo.',
            'validation.password.uncompromised' => 'O :attribute fornecido apareceu numa fuga de dados. Por favor escolha um :attribute diferente.',
            'validation.present'             => 'O campo :attribute deve estar presente.',
            'validation.present_if'          => 'O campo :attribute deve estar presente quando :other é :value.',
            'validation.present_unless'      => 'O campo :attribute deve estar presente a menos que :other seja :value.',
            'validation.present_with'        => 'O campo :attribute deve estar presente quando :values está presente.',
            'validation.present_with_all'    => 'O campo :attribute deve estar presente quando :values estão presentes.',
            'validation.prohibited'          => 'O campo :attribute é proibido.',
            'validation.prohibited_if'       => 'O campo :attribute é proibido quando :other é :value.',
            'validation.prohibited_if_accepted' => 'O campo :attribute é proibido quando :other é aceite.',
            'validation.prohibited_if_declined' => 'O campo :attribute é proibido quando :other é recusado.',
            'validation.prohibited_unless'   => 'O campo :attribute é proibido a menos que :other esteja em :values.',
            'validation.prohibits'           => 'O campo :attribute proíbe a presença de :other.',
            'validation.regex'               => 'O formato do campo :attribute é inválido.',
            'validation.required'            => 'O campo :attribute é obrigatório.',
            'validation.required_array_keys' => 'O campo :attribute deve conter entradas para: :values.',
            'validation.required_if'         => 'O campo :attribute é obrigatório quando :other é :value.',
            'validation.required_if_accepted'=> 'O campo :attribute é obrigatório quando :other é aceite.',
            'validation.required_if_declined'=> 'O campo :attribute é obrigatório quando :other é recusado.',
            'validation.required_unless'     => 'O campo :attribute é obrigatório a menos que :other esteja em :values.',
            'validation.required_with'       => 'O campo :attribute é obrigatório quando :values está presente.',
            'validation.required_with_all'   => 'O campo :attribute é obrigatório quando :values estão presentes.',
            'validation.required_without'    => 'O campo :attribute é obrigatório quando :values não está presente.',
            'validation.required_without_all'=> 'O campo :attribute é obrigatório quando nenhum de :values está presente.',
            'validation.same'                => 'O campo :attribute deve coincidir com :other.',
            'validation.size.array'          => 'O campo :attribute deve conter :size itens.',
            'validation.size.file'           => 'O campo :attribute deve ter :size kilobytes.',
            'validation.size.numeric'        => 'O campo :attribute deve ser :size.',
            'validation.size.string'         => 'O campo :attribute deve ter :size caracteres.',
            'validation.starts_with'         => 'O campo :attribute deve começar com um dos seguintes: :values.',
            'validation.string'              => 'O campo :attribute deve ser uma string.',
            'validation.timezone'            => 'O campo :attribute deve ser um fuso horário válido.',
            'validation.unique'              => 'O :attribute já foi utilizado.',
            'validation.uploaded'            => 'O :attribute falhou ao fazer o upload.',
            'validation.uppercase'           => 'O campo :attribute deve estar em maiúsculas.',
            'validation.url'                 => 'O campo :attribute deve ser um URL válido.',
            'validation.ulid'                => 'O campo :attribute deve ser um ULID válido.',
            'validation.uuid'                => 'O campo :attribute deve ser um UUID válido.',
            'validation.custom.attribute-name.rule-name' => 'mensagem-personalizada',
        ];
    }

    /**
     * All EN values, keyed by dot-notation key.
     * Derived from the uploaded lang files.
     */
    private function enTranslations(): array
    {
        $auth = [
            'failed'   => 'These credentials do not match our records.',
            'password' => 'The provided password is incorrect.',
            'throttle' => 'Too many login attempts. Please try again in :seconds seconds.',
        ];

        $passwords = [
            'reset'     => 'Your password has been reset.',
            'sent'      => 'We have emailed your password reset link.',
            'throttled' => 'Please wait before retrying.',
            'token'     => 'This password reset token is invalid.',
            'user'      => "We can't find a user with that email address.",
        ];

        $pagination = [
            'previous' => '&laquo; Previous',
            'next'     => 'Next &raquo;',
        ];

        $kyc = [
            'not_found'    => 'The KYC record was not found.',
            'submitted'    => 'Your KYC has been submitted successfully and is pending review.',
            'updated'      => 'Your KYC has been updated successfully.',
            'approved'     => 'Your KYC has been approved successfully.',
            'rejected'     => 'Your KYC has been rejected.',
            'cannot_submit'=> 'You cannot submit a new KYC at this time.',
            'cannot_edit'  => 'You cannot edit your KYC at this time.',
        ];

        $notifications = [
            'kyc' => [
                'salutation' => 'With best regards, :app_name',
                'submitted' => [
                    'subject' => 'New KYC Submission',
                    'message' => 'The vendor :name has submitted a new KYC for review.',
                ],
                'updated' => [
                    'subject' => 'KYC Updated',
                    'message' => 'The vendor :name has updated their KYC and is awaiting review.',
                ],
                'approved' => [
                    'subject'    => 'Your KYC has been approved',
                    'greeting'   => 'Hello, :name!',
                    'message'    => 'Your identity verification (KYC) process has been approved successfully.',
                    'expiration' => 'Your verification is valid until :date.',
                    'action'     => 'Go to Dashboard',
                ],
                'rejected' => [
                    'subject'  => 'Your KYC has been rejected',
                    'greeting' => 'Hello, :name!',
                    'message'  => 'Your identity verification (KYC) process has been rejected.',
                    'reason'   => 'Reason: :reason',
                    'warning'  => 'Please correct the indicated issues and resubmit.',
                    'action'   => 'Fix KYC',
                ],
            ],
        ];

        $validation = [
            'accepted'        => 'The :attribute field must be accepted.',
            'accepted_if'     => 'The :attribute field must be accepted when :other is :value.',
            'active_url'      => 'The :attribute field must be a valid URL.',
            'after'           => 'The :attribute field must be a date after :date.',
            'after_or_equal'  => 'The :attribute field must be a date after or equal to :date.',
            'alpha'           => 'The :attribute field must only contain letters.',
            'alpha_dash'      => 'The :attribute field must only contain letters, numbers, dashes, and underscores.',
            'alpha_num'       => 'The :attribute field must only contain letters and numbers.',
            'any_of'          => 'The :attribute field is invalid.',
            'array'           => 'The :attribute field must be an array.',
            'ascii'           => 'The :attribute field must only contain single-byte alphanumeric characters and symbols.',
            'before'          => 'The :attribute field must be a date before :date.',
            'before_or_equal' => 'The :attribute field must be a date before or equal to :date.',
            'between' => [
                'array'   => 'The :attribute field must have between :min and :max items.',
                'file'    => 'The :attribute field must be between :min and :max kilobytes.',
                'numeric' => 'The :attribute field must be between :min and :max.',
                'string'  => 'The :attribute field must be between :min and :max characters.',
            ],
            'boolean'      => 'The :attribute field must be true or false.',
            'can'          => 'The :attribute field contains an unauthorized value.',
            'confirmed'    => 'The :attribute field confirmation does not match.',
            'contains'     => 'The :attribute field is missing a required value.',
            'current_password' => 'The password is incorrect.',
            'date'         => 'The :attribute field must be a valid date.',
            'date_equals'  => 'The :attribute field must be a date equal to :date.',
            'date_format'  => 'The :attribute field must match the format :format.',
            'decimal'      => 'The :attribute field must have :decimal decimal places.',
            'declined'     => 'The :attribute field must be declined.',
            'declined_if'  => 'The :attribute field must be declined when :other is :value.',
            'different'    => 'The :attribute field and :other must be different.',
            'digits'       => 'The :attribute field must be :digits digits.',
            'digits_between' => 'The :attribute field must be between :min and :max digits.',
            'dimensions'   => 'The :attribute field has invalid image dimensions.',
            'distinct'     => 'The :attribute field has a duplicate value.',
            'doesnt_contain'    => 'The :attribute field must not contain any of the following: :values.',
            'doesnt_end_with'   => 'The :attribute field must not end with one of the following: :values.',
            'doesnt_start_with' => 'The :attribute field must not start with one of the following: :values.',
            'email'        => 'The :attribute field must be a valid email address.',
            'encoding'     => 'The :attribute field must be encoded in :encoding.',
            'ends_with'    => 'The :attribute field must end with one of the following: :values.',
            'enum'         => 'The selected :attribute is invalid.',
            'exists'       => 'The selected :attribute is invalid.',
            'extensions'   => 'The :attribute field must have one of the following extensions: :values.',
            'file'         => 'The :attribute field must be a file.',
            'filled'       => 'The :attribute field must have a value.',
            'gt' => [
                'array'   => 'The :attribute field must have more than :value items.',
                'file'    => 'The :attribute field must be greater than :value kilobytes.',
                'numeric' => 'The :attribute field must be greater than :value.',
                'string'  => 'The :attribute field must be greater than :value characters.',
            ],
            'gte' => [
                'array'   => 'The :attribute field must have :value items or more.',
                'file'    => 'The :attribute field must be greater than or equal to :value kilobytes.',
                'numeric' => 'The :attribute field must be greater than or equal to :value.',
                'string'  => 'The :attribute field must be greater than or equal to :value characters.',
            ],
            'hex_color'    => 'The :attribute field must be a valid hexadecimal color.',
            'image'        => 'The :attribute field must be an image.',
            'in'           => 'The selected :attribute is invalid.',
            'in_array'     => 'The :attribute field must exist in :other.',
            'in_array_keys'=> 'The :attribute field must contain at least one of the following keys: :values.',
            'integer'      => 'The :attribute field must be an integer.',
            'ip'           => 'The :attribute field must be a valid IP address.',
            'ipv4'         => 'The :attribute field must be a valid IPv4 address.',
            'ipv6'         => 'The :attribute field must be a valid IPv6 address.',
            'json'         => 'The :attribute field must be a valid JSON string.',
            'list'         => 'The :attribute field must be a list.',
            'lowercase'    => 'The :attribute field must be lowercase.',
            'lt' => [
                'array'   => 'The :attribute field must have less than :value items.',
                'file'    => 'The :attribute field must be less than :value kilobytes.',
                'numeric' => 'The :attribute field must be less than :value.',
                'string'  => 'The :attribute field must be less than :value characters.',
            ],
            'lte' => [
                'array'   => 'The :attribute field must not have more than :value items.',
                'file'    => 'The :attribute field must be less than or equal to :value kilobytes.',
                'numeric' => 'The :attribute field must be less than or equal to :value.',
                'string'  => 'The :attribute field must be less than or equal to :value characters.',
            ],
            'mac_address'  => 'The :attribute field must be a valid MAC address.',
            'max' => [
                'array'   => 'The :attribute field must not have more than :max items.',
                'file'    => 'The :attribute field must not be greater than :max kilobytes.',
                'numeric' => 'The :attribute field must not be greater than :max.',
                'string'  => 'The :attribute field must not be greater than :max characters.',
            ],
            'max_digits'   => 'The :attribute field must not have more than :max digits.',
            'mimes'        => 'The :attribute field must be a file of type: :values.',
            'mimetypes'    => 'The :attribute field must be a file of type: :values.',
            'min' => [
                'array'   => 'The :attribute field must have at least :min items.',
                'file'    => 'The :attribute field must be at least :min kilobytes.',
                'numeric' => 'The :attribute field must be at least :min.',
                'string'  => 'The :attribute field must be at least :min characters.',
            ],
            'min_digits'   => 'The :attribute field must have at least :min digits.',
            'missing'      => 'The :attribute field must be missing.',
            'missing_if'   => 'The :attribute field must be missing when :other is :value.',
            'missing_unless'    => 'The :attribute field must be missing unless :other is :value.',
            'missing_with'      => 'The :attribute field must be missing when :values is present.',
            'missing_with_all'  => 'The :attribute field must be missing when :values are present.',
            'multiple_of'  => 'The :attribute field must be a multiple of :value.',
            'not_in'       => 'The selected :attribute is invalid.',
            'not_regex'    => 'The :attribute field format is invalid.',
            'numeric'      => 'The :attribute field must be a number.',
            'password' => [
                'letters'        => 'The :attribute field must contain at least one letter.',
                'mixed'          => 'The :attribute field must contain at least one uppercase and one lowercase letter.',
                'numbers'        => 'The :attribute field must contain at least one number.',
                'symbols'        => 'The :attribute field must contain at least one symbol.',
                'uncompromised'  => 'The given :attribute has appeared in a data leak. Please choose a different :attribute.',
            ],
            'present'           => 'The :attribute field must be present.',
            'present_if'        => 'The :attribute field must be present when :other is :value.',
            'present_unless'    => 'The :attribute field must be present unless :other is :value.',
            'present_with'      => 'The :attribute field must be present when :values is present.',
            'present_with_all'  => 'The :attribute field must be present when :values are present.',
            'prohibited'            => 'The :attribute field is prohibited.',
            'prohibited_if'         => 'The :attribute field is prohibited when :other is :value.',
            'prohibited_if_accepted'=> 'The :attribute field is prohibited when :other is accepted.',
            'prohibited_if_declined'=> 'The :attribute field is prohibited when :other is declined.',
            'prohibited_unless'     => 'The :attribute field is prohibited unless :other is in :values.',
            'prohibits'             => 'The :attribute field prohibits :other from being present.',
            'regex'                 => 'The :attribute field format is invalid.',
            'required'              => 'The :attribute field is required.',
            'required_array_keys'   => 'The :attribute field must contain entries for: :values.',
            'required_if'           => 'The :attribute field is required when :other is :value.',
            'required_if_accepted'  => 'The :attribute field is required when :other is accepted.',
            'required_if_declined'  => 'The :attribute field is required when :other is declined.',
            'required_unless'       => 'The :attribute field is required unless :other is in :values.',
            'required_with'         => 'The :attribute field is required when :values is present.',
            'required_with_all'     => 'The :attribute field is required when :values are present.',
            'required_without'      => 'The :attribute field is required when :values is not present.',
            'required_without_all'  => 'The :attribute field is required when none of :values are present.',
            'same'                  => 'The :attribute field must match :other.',
            'size' => [
                'array'   => 'The :attribute field must contain :size items.',
                'file'    => 'The :attribute field must be :size kilobytes.',
                'numeric' => 'The :attribute field must be :size.',
                'string'  => 'The :attribute field must be :size characters.',
            ],
            'starts_with'  => 'The :attribute field must start with one of the following: :values.',
            'string'       => 'The :attribute field must be a string.',
            'timezone'     => 'The :attribute field must be a valid timezone.',
            'unique'       => 'The :attribute has already been taken.',
            'uploaded'     => 'The :attribute failed to upload.',
            'uppercase'    => 'The :attribute field must be uppercase.',
            'url'          => 'The :attribute field must be a valid URL.',
            'ulid'         => 'The :attribute field must be a valid ULID.',
            'uuid'         => 'The :attribute field must be a valid UUID.',
            'custom' => [
                'attribute-name' => [
                    'rule-name' => 'custom-message',
                ],
            ],
        ];

        $result = [];
        foreach ($this->flatten($auth, 'auth') as $k => $v) {
            $result[$k] = $v;
        }
        foreach ($this->flatten($passwords, 'passwords') as $k => $v) {
            $result[$k] = $v;
        }
        foreach ($this->flatten($pagination, 'pagination') as $k => $v) {
            $result[$k] = $v;
        }
        foreach ($this->flatten($kyc, 'kyc') as $k => $v) {
            $result[$k] = $v;
        }
        foreach ($this->flatten($notifications, 'notifications') as $k => $v) {
            $result[$k] = $v;
        }
        foreach ($this->flatten($validation, 'validation') as $k => $v) {
            $result[$k] = $v;
        }

        return $result;
    }

    public function run(): void
    {
        $now   = Carbon::now();
        $enAll = $this->enTranslations();
        $ptAll = $this->ptTranslations();

        foreach ($enAll as $key => $enValue) {
            // ── 1. Upsert the translation key ────────────────────────────────────
            $existing = DB::table('translations')->where('key', $key)->first();

            if ($existing) {
                $translationId = $existing->id;
            } else {
                $translationId = DB::table('translations')->insertGetId([
                    'key'        => $key,
                    'label'      => $this->makeLabel($key),
                    'context'    => null,
                    'is_system'  => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            // ── 2. Upsert EN value ───────────────────────────────────────────────
            $enExists = DB::table('translation_values')
                ->where('translation_id', $translationId)
                ->where('locale', 'en')
                ->exists();

            if (! $enExists) {
                DB::table('translation_values')->insert([
                    'translation_id' => $translationId,
                    'locale'         => 'en',
                    'value_short'    => mb_substr($enValue, 0, 100),
                    'value'          => $enValue,
                    'value_long'     => null,
                    'value_html'     => null,
                    'status'         => 'done',
                    'updated_by'     => null,
                    'translated_at'  => $now,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ]);
            }

            // ── 3. Upsert PT value ───────────────────────────────────────────────
            $ptValue  = $ptAll[$key] ?? null;
            $ptExists = DB::table('translation_values')
                ->where('translation_id', $translationId)
                ->where('locale', 'pt')
                ->exists();

            if ($ptValue && ! $ptExists) {
                DB::table('translation_values')->insert([
                    'translation_id' => $translationId,
                    'locale'         => 'pt',
                    'value_short'    => mb_substr($ptValue, 0, 100),
                    'value'          => $ptValue,
                    'value_long'     => null,
                    'value_html'     => null,
                    'status'         => 'done',
                    'updated_by'     => null,
                    'translated_at'  => $now,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ]);
            }
        }

        $total = count($enAll);
        $this->command->info("LangFilesTranslationSeeder: processed {$total} translation keys (en + pt).");
    }
}