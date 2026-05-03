<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use App\Models\UserType;
use App\Models\Kyc;
use Illuminate\Database\Eloquent\Relations\HasOne;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'image',
        'user_type_id', // Add user_type to the fillable attributes
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function userType()
    {
        return $this->belongsTo(UserType::class);
    }

    public function hasRole($role)
    {
        return $this->userType && strtolower($this->userType->name) === strtolower($role);
    }

    public function hasAnyRole(array $roles)
    {
        return $this->userType && in_array(strtolower($this->userType->name), array_map('strtolower', $roles));
    }

    
    public function isVendor()
    {
        // return $this->userType && $this->userType->id === 2;
        return $this->hasRole('vendor');
    }

    /**
     * Get the user's KYC record (latest if multiple exist).
     */
    public function kyc(): HasOne
    {
        return $this->hasOne(Kyc::class)->latestOfMany();
    }

    /**
     * Get the user's KYC records history (all records).
     */
    public function kycs(){
        return $this->hasMany(Kyc::class)->orderBy('created_at', 'desc');
    }

    public function canSubmitKyc()
    {
        return $this->isVendor() && (
            ! $this->kyc ||
            $this->kyc->isRejected() ||
            $this->kyc->isExpired()
        );
    }
}
