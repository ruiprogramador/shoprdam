<?php

namespace Database\Seeders;

use App\Models\Translation;
use Illuminate\Database\Seeder;

class TranslationSeeder extends Seeder
{
    public function run(): void
    {
        $translations = [
            'en' => [
                'common' => [
                    'select'   => ['short' => 'Select',   'text' => 'Select an option'],
                    'cancel'   => ['short' => 'Cancel',   'text' => 'Cancel'],
                    'save'     => ['short' => 'Save',     'text' => 'Save changes'],
                    'delete'   => ['short' => 'Delete',   'text' => 'Delete this record'],
                    'edit'     => ['short' => 'Edit',     'text' => 'Edit this record'],
                    'view'     => ['short' => 'View',     'text' => 'View details'],
                    'search'   => ['short' => 'Search',   'text' => 'Search...'],
                    'loading'  => ['short' => 'Loading',  'text' => 'Loading...'],
                    'yes'      => ['short' => 'Yes',      'text' => 'Yes'],
                    'no'       => ['short' => 'No',       'text' => 'No'],
                    'back'     => ['short' => 'Back',     'text' => 'Go back'],
                    'next'     => ['short' => 'Next',     'text' => 'Continue'],
                    'submit'   => ['short' => 'Submit',   'text' => 'Submit'],
                    'confirm'  => ['short' => 'Confirm',  'text' => 'Confirm action'],
                    'close'    => ['short' => 'Close',    'text' => 'Close'],
                    'export'   => ['short' => 'Export',   'text' => 'Export to Excel'],
                ],
                'kyc' => [
                    'not_found'     => ['short' => 'No KYC',     'text' => 'You have no KYC process yet.',                          'long' => 'To operate as a vendor, you need to verify your identity by submitting a KYC.'],
                    'submitted'     => ['short' => 'Submitted',  'text' => 'Your KYC has been submitted and is awaiting review.',    'long' => null],
                    'updated'       => ['short' => 'Updated',    'text' => 'Your KYC has been updated successfully.',               'long' => null],
                    'approved'      => ['short' => 'Approved',   'text' => 'The KYC has been approved successfully.',               'long' => null],
                    'rejected'      => ['short' => 'Rejected',   'text' => 'The KYC has been rejected.',                           'long' => null],
                    'cannot_submit' => ['short' => null,         'text' => 'You cannot submit a new KYC at this time.',            'long' => null],
                    'cannot_edit'   => ['short' => null,         'text' => 'You cannot edit your KYC at this time.',               'long' => null],
                ],
                'notifications' => [
                    'kyc.submitted'           => ['short' => 'New KYC',   'text' => 'Vendor :name has submitted a new KYC for review.',         'long' => null],
                    'kyc.updated'             => ['short' => 'KYC Updated', 'text' => 'Vendor :name has updated their KYC and awaits review.',  'long' => null],
                    'kyc.approved.subject'    => ['short' => 'Approved',  'text' => 'Your KYC has been approved',                              'long' => null],
                    'kyc.approved.message'    => ['short' => 'Approved',  'text' => 'Your identity verification has been successfully approved.', 'long' => 'Your KYC has been approved. You can now operate as a vendor on our platform.'],
                    'kyc.approved.expiration' => ['short' => null,        'text' => 'Your verification is valid until :date.',                  'long' => null],
                    'kyc.rejected.subject'    => ['short' => 'Rejected',  'text' => 'Your KYC has been rejected',                              'long' => null],
                    'kyc.rejected.message'    => ['short' => 'Rejected',  'text' => 'Your identity verification has been rejected.',            'long' => 'Your KYC submission has been reviewed and unfortunately could not be approved. Please review the rejection reason and resubmit.'],
                    'kyc.rejected.reason'     => ['short' => null,        'text' => 'Reason: :reason',                                         'long' => null],
                    'kyc.rejected.warning'    => ['short' => null,        'text' => 'Please correct the issues and resubmit.',                  'long' => null],
                ],
            ],
            'pt' => [
                'common' => [
                    'select'   => ['short' => 'Selecionar', 'text' => 'Selecione uma opção'],
                    'cancel'   => ['short' => 'Cancelar',   'text' => 'Cancelar'],
                    'save'     => ['short' => 'Guardar',    'text' => 'Guardar alterações'],
                    'delete'   => ['short' => 'Eliminar',   'text' => 'Eliminar este registo'],
                    'edit'     => ['short' => 'Editar',     'text' => 'Editar este registo'],
                    'view'     => ['short' => 'Ver',        'text' => 'Ver detalhes'],
                    'search'   => ['short' => 'Pesquisar',  'text' => 'Pesquisar...'],
                    'loading'  => ['short' => 'A carregar', 'text' => 'A carregar...'],
                    'yes'      => ['short' => 'Sim',        'text' => 'Sim'],
                    'no'       => ['short' => 'Não',        'text' => 'Não'],
                    'back'     => ['short' => 'Voltar',     'text' => 'Voltar atrás'],
                    'next'     => ['short' => 'Seguinte',   'text' => 'Continuar'],
                    'submit'   => ['short' => 'Submeter',   'text' => 'Submeter'],
                    'confirm'  => ['short' => 'Confirmar',  'text' => 'Confirmar ação'],
                    'close'    => ['short' => 'Fechar',     'text' => 'Fechar'],
                    'export'   => ['short' => 'Exportar',   'text' => 'Exportar para Excel'],
                ],
                'kyc' => [
                    'not_found'     => ['short' => 'Sem KYC',    'text' => 'Não tem nenhum processo KYC.',                                  'long' => 'Para operar como vendor, precisa de verificar a sua identidade submetendo um KYC.'],
                    'submitted'     => ['short' => 'Submetido',  'text' => 'O seu KYC foi submetido com sucesso e aguarda revisão.',        'long' => null],
                    'updated'       => ['short' => 'Atualizado', 'text' => 'O seu KYC foi atualizado com sucesso.',                        'long' => null],
                    'approved'      => ['short' => 'Aprovado',   'text' => 'O KYC foi aprovado com sucesso.',                             'long' => null],
                    'rejected'      => ['short' => 'Rejeitado',  'text' => 'O KYC foi rejeitado.',                                        'long' => null],
                    'cannot_submit' => ['short' => null,         'text' => 'Não é possível submeter um novo KYC neste momento.',          'long' => null],
                    'cannot_edit'   => ['short' => null,         'text' => 'Não é possível editar o seu KYC neste momento.',              'long' => null],
                ],
                'notifications' => [
                    'kyc.submitted'           => ['short' => 'Novo KYC',     'text' => 'O vendor :name submeteu um novo KYC para revisão.',          'long' => null],
                    'kyc.updated'             => ['short' => 'KYC Atualizado', 'text' => 'O vendor :name atualizou o seu KYC e aguarda revisão.',    'long' => null],
                    'kyc.approved.subject'    => ['short' => 'Aprovado',     'text' => 'O seu KYC foi aprovado',                                    'long' => null],
                    'kyc.approved.message'    => ['short' => 'Aprovado',     'text' => 'O seu processo de verificação foi aprovado com sucesso.',    'long' => 'O seu KYC foi aprovado. Já pode operar como vendor na nossa plataforma.'],
                    'kyc.approved.expiration' => ['short' => null,           'text' => 'A sua verificação é válida até :date.',                     'long' => null],
                    'kyc.rejected.subject'    => ['short' => 'Rejeitado',    'text' => 'O seu KYC foi rejeitado',                                   'long' => null],
                    'kyc.rejected.message'    => ['short' => 'Rejeitado',    'text' => 'O seu processo de verificação foi rejeitado.',               'long' => 'A sua submissão foi revista e infelizmente não pôde ser aprovada. Por favor reveja o motivo e submeta novamente.'],
                    'kyc.rejected.reason'     => ['short' => null,           'text' => 'Motivo: :reason',                                           'long' => null],
                    'kyc.rejected.warning'    => ['short' => null,           'text' => 'Por favor corrija os problemas e submeta novamente.',        'long' => null],
                ],
            ],
        ];

        foreach ($translations as $locale => $groups) {
            foreach ($groups as $group => $keys) {
                foreach ($keys as $key => $variants) {
                    Translation::updateOrCreate(
                        ['locale' => $locale, 'group' => $group, 'key' => $key],
                        [
                            'text_short' => $variants['short'] ?? null,
                            'text'       => $variants['text']  ?? null,
                            'text_long'  => $variants['long']  ?? null,
                            'text_html'  => $variants['html']  ?? null,
                        ]
                    );
                }
            }
        }
    }
}