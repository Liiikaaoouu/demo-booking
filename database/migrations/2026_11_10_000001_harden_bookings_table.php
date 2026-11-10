<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // USING задан явно: без него Postgres возьмёт часовой пояс сессии.
        DB::statement(<<<'SQL'
            ALTER TABLE bookings
                ALTER COLUMN starts_at TYPE timestamptz USING starts_at AT TIME ZONE 'UTC',
                ALTER COLUMN ends_at TYPE timestamptz USING ends_at AT TIME ZONE 'UTC'
        SQL);

        Schema::table('bookings', function (Blueprint $table) {
            $table->index(['room_id', 'starts_at', 'ends_at']);
        });

        // Пересечения запрещает база — это работает и для параллельных вставок,
        // и для записи мимо приложения. Добавление упадёт, если в таблице уже
        // есть конфликтующие строки: их нужно разрешить до выката.
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
        DB::statement(<<<'SQL'
            ALTER TABLE bookings ADD CONSTRAINT bookings_room_time_no_overlap
            EXCLUDE USING gist (
                room_id WITH =,
                tstzrange(starts_at, ends_at, '[)') WITH &&
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE bookings DROP CONSTRAINT IF EXISTS bookings_room_time_no_overlap');

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['room_id', 'starts_at', 'ends_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE bookings
                ALTER COLUMN starts_at TYPE timestamp USING starts_at AT TIME ZONE 'UTC',
                ALTER COLUMN ends_at TYPE timestamp USING ends_at AT TIME ZONE 'UTC'
        SQL);
    }
};
