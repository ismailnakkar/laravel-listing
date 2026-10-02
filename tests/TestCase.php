<?php

declare(strict_types=1);

namespace Listing\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Testbench;

/**
 * Testbench on in-memory SQLite by default; Laravel's own DB_CONNECTION and DB_* variables run the same suite on a
 * server database, so tables are dropped first and nothing pins a connection.
 */
abstract class TestCase extends Testbench
{
    protected function setUp(): void
    {
        parent::setUp();

        // Each test boots a new app; without this its server connection stays open, and PostgreSQL's 100 run out.
        $this->beforeApplicationDestroyed(fn () => DB::disconnect());

        Schema::dropAllTables();

        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->nullable();
            $table->string('name');
            $table->string('reference')->nullable();
            $table->string('kind')->nullable();
            $table->unsignedTinyInteger('status')->nullable();
            $table->boolean('paid')->default(false);
            $table->integer('price')->default(0);
            $table->date('day')->nullable();
            $table->date('day_cast')->nullable();
            $table->dateTime('at', 3)->nullable();
            $table->timestamps();
        });

        Schema::create('teams', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        Schema::create('members', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('member_team', function (Blueprint $table): void {
            $table->foreignId('member_id');
            $table->foreignId('team_id');
            $table->string('role');
            $table->timestamps();
        });
    }
}
