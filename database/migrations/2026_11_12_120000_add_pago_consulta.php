<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('The clinic schema requires PostgreSQL.');
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE IF NOT EXISTS public.pago_consulta (
   id_pago INTEGER GENERATED ALWAYS AS IDENTITY NOT NULL,
   fecha_hora TIMESTAMPTZ NOT NULL,
   monto NUMERIC(12,2) NOT NULL,
   moneda VARCHAR(3) NOT NULL,
   metodo VARCHAR(20) NOT NULL,
   estado VARCHAR(20) NOT NULL,
   referencia_externa VARCHAR(200),
   id_cita INTEGER NOT NULL,
   id_usuario INTEGER NOT NULL,
   CONSTRAINT pk_pago_consulta PRIMARY KEY (id_pago),
   CONSTRAINT uq_pago_consulta_referencia UNIQUE (referencia_externa),
   CONSTRAINT fk_pago_consulta_cita FOREIGN KEY (id_cita)
       REFERENCES public.cita_medica (id_cita),
   CONSTRAINT fk_pago_consulta_usuario FOREIGN KEY (id_usuario)
       REFERENCES public.usuario (id_usuario),
   CONSTRAINT ck_pago_consulta_monto CHECK (monto > 0),
   CONSTRAINT ck_pago_consulta_moneda CHECK (moneda IN ('BOB')),
   CONSTRAINT ck_pago_consulta_metodo CHECK (metodo IN ('STRIPE')),
   CONSTRAINT ck_pago_consulta_estado CHECK (
       estado IN ('Pendiente', 'Confirmado', 'Rechazado', 'Devuelto')
   )
);
CREATE INDEX IF NOT EXISTS ix_pago_consulta_cita ON public.pago_consulta (id_cita);
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS public.pago_consulta');
    }
};
