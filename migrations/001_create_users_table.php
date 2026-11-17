<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Core\Database\Migration;

class CreateUsersTable extends Migration
{
    public function up(): void
    {
        $this->createTable('users', function($table) {
            $table->id();
            $table->string('email', 255)->unique();
            $table->string('name', 255);
            $table->string('password', 255);
            $table->enum('role', ['admin', 'buyer', 'supplier', 'user'])->default('user');
            $table->boolean('is_active')->default(true);
            $table->timestampNullable('email_verified_at');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        $this->dropTable('users');
    }
}
