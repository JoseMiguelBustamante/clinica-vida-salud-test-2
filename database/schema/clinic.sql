-- Source: approved clinic report, physical schema, PDF pages 126-135.
-- Laravel owns the transaction; statements and constraints are preserved.
SET LOCAL search_path = public;

CREATE TABLE public.rol (
   id_rol INTEGER GENERATED ALWAYS AS IDENTITY NOT NULL,
   nombre VARCHAR(100) NOT NULL,
   descripcion TEXT,
   CONSTRAINT pk_rol PRIMARY KEY (id_rol),
   CONSTRAINT uq_rol_nombre UNIQUE (nombre)
);

CREATE TABLE public.permiso (
   id_permiso INTEGER GENERATED ALWAYS AS IDENTITY NOT NULL,
   codigo VARCHAR(100) NOT NULL,
   descripcion TEXT,

   CONSTRAINT pk_permiso PRIMARY KEY (id_permiso),
   CONSTRAINT uq_permiso_codigo UNIQUE (codigo)
);

CREATE TABLE public.usuario (
   id_usuario INTEGER GENERATED ALWAYS AS IDENTITY NOT NULL,
   nombre_usuario VARCHAR(100) NOT NULL,
   correo_acceso VARCHAR(255),
   contrasena_hash VARCHAR(255) NOT NULL,
   activo BOOLEAN NOT NULL,
   id_rol INTEGER NOT NULL,
   CONSTRAINT pk_usuario PRIMARY KEY (id_usuario),
   CONSTRAINT uq_usuario_nombre UNIQUE (nombre_usuario),
   CONSTRAINT uq_usuario_correo UNIQUE (correo_acceso),
   CONSTRAINT fk_usuario_rol FOREIGN KEY (id_rol)
       REFERENCES public.rol (id_rol)
);

CREATE TABLE public.rol_permiso (
   id_rol INTEGER NOT NULL,
   id_permiso INTEGER NOT NULL,
   CONSTRAINT pk_rol_permiso PRIMARY KEY (id_rol, id_permiso),
   CONSTRAINT fk_rol_permiso_rol FOREIGN KEY (id_rol)
       REFERENCES public.rol (id_rol),
   CONSTRAINT fk_rol_permiso_permiso FOREIGN KEY (id_permiso)
       REFERENCES public.permiso (id_permiso)
);

-- La aplicación convierte CI vacío en NULL antes de guardar.

CREATE TABLE public.paciente (
   id_paciente INTEGER GENERATED ALWAYS AS IDENTITY NOT NULL,
   ci VARCHAR(30),
   nombres VARCHAR(100) NOT NULL,
   apellidos VARCHAR(100) NOT NULL,
   fecha_nacimiento DATE,
   sexo VARCHAR(30),
   telefono VARCHAR(30),
   correo_contacto VARCHAR(255),
   direccion TEXT,
   activo BOOLEAN NOT NULL,
   fecha_registro TIMESTAMPTZ NOT NULL,
   id_usuario INTEGER,
   CONSTRAINT pk_paciente PRIMARY KEY (id_paciente),
   CONSTRAINT uq_paciente_ci UNIQUE (ci),
   CONSTRAINT uq_paciente_usuario UNIQUE (id_usuario),
   CONSTRAINT fk_paciente_usuario FOREIGN KEY (id_usuario)
       REFERENCES public.usuario (id_usuario)
);

CREATE TABLE public.medico (
   id_medico INTEGER GENERATED ALWAYS AS IDENTITY NOT NULL,
   registro_profesional VARCHAR(50) NOT NULL,
   nombres VARCHAR(100) NOT NULL,
   apellidos VARCHAR(100) NOT NULL,
   telefono VARCHAR(30),
   correo_contacto VARCHAR(255),
   activo BOOLEAN NOT NULL,
   id_usuario INTEGER,

   CONSTRAINT pk_medico PRIMARY KEY (id_medico),
   CONSTRAINT uq_medico_registro UNIQUE (registro_profesional),
   CONSTRAINT uq_medico_usuario UNIQUE (id_usuario),
   CONSTRAINT fk_medico_usuario FOREIGN KEY (id_usuario)
       REFERENCES public.usuario (id_usuario)
);

-- UNIQUE(nombre) no implementa una normalización aún no acordada.
CREATE TABLE public.especialidad (
   id_especialidad INTEGER GENERATED ALWAYS AS IDENTITY NOT NULL,
   nombre VARCHAR(100) NOT NULL,
   descripcion TEXT,
   activo BOOLEAN NOT NULL,
   CONSTRAINT pk_especialidad PRIMARY KEY (id_especialidad),
   CONSTRAINT uq_especialidad_nombre UNIQUE (nombre)
);

-- La PK compuesta no exige una especialidad mínima por cada médico.
CREATE TABLE public.medico_especialidad (
   id_medico INTEGER NOT NULL,
   id_especialidad INTEGER NOT NULL,
   CONSTRAINT pk_medico_especialidad PRIMARY KEY (id_medico, id_especialidad),
   CONSTRAINT fk_medico_especialidad_medico FOREIGN KEY (id_medico)
       REFERENCES public.medico (id_medico),
   CONSTRAINT fk_medico_especialidad_especialidad FOREIGN KEY (id_especialidad)
       REFERENCES public.especialidad (id_especialidad)
);

CREATE TABLE public.horario_atencion (

   id_horario INTEGER GENERATED ALWAYS AS IDENTITY NOT NULL,
   dia_semana VARCHAR(10) NOT NULL,
   hora_inicio TIME NOT NULL,
   hora_fin TIME NOT NULL,
   duracion_cita_minutos INTEGER NOT NULL,
   activo BOOLEAN NOT NULL,
   id_medico INTEGER NOT NULL,
   CONSTRAINT pk_horario_atencion PRIMARY KEY (id_horario),
   CONSTRAINT fk_horario_medico FOREIGN KEY (id_medico)
       REFERENCES public.medico (id_medico),
   CONSTRAINT ck_horario_dia CHECK (
       dia_semana IN ('Lunes', 'Martes', 'Miercoles', 'Jueves',
                      'Viernes', 'Sabado', 'Domingo')
   ),
   CONSTRAINT ck_horario_intervalo CHECK (hora_fin > hora_inicio),
   CONSTRAINT ck_horario_duracion CHECK (duracion_cita_minutos > 0)
);

-- No garantiza ausencia de solapamientos sin protocolo transaccional común.
CREATE TABLE public.cita_medica (
   id_cita INTEGER GENERATED ALWAYS AS IDENTITY NOT NULL,
   codigo VARCHAR(30) NOT NULL,
   inicio TIMESTAMPTZ NOT NULL,
   fin TIMESTAMPTZ NOT NULL,
   motivo_consulta TEXT,
   modalidad VARCHAR(15) NOT NULL,
   estado VARCHAR(20) NOT NULL,
   fecha_creacion TIMESTAMPTZ NOT NULL,
   fecha_hora_llegada TIMESTAMPTZ,

   fecha_hora_registro_atencion TIMESTAMPTZ,
   motivo_cancelacion TEXT,
   id_paciente INTEGER NOT NULL,
   id_medico INTEGER NOT NULL,
   id_especialidad INTEGER NOT NULL,
   CONSTRAINT pk_cita_medica PRIMARY KEY (id_cita),
   CONSTRAINT uq_cita_codigo UNIQUE (codigo),
   CONSTRAINT fk_cita_paciente FOREIGN KEY (id_paciente)
       REFERENCES public.paciente (id_paciente),
   CONSTRAINT fk_cita_medico FOREIGN KEY (id_medico)
       REFERENCES public.medico (id_medico),
   CONSTRAINT fk_cita_especialidad FOREIGN KEY (id_especialidad)
       REFERENCES public.especialidad (id_especialidad),
   CONSTRAINT fk_cita_medico_especialidad FOREIGN KEY (id_medico, id_especialidad)
       REFERENCES public.medico_especialidad (id_medico, id_especialidad),
   CONSTRAINT ck_cita_intervalo CHECK (fin > inicio),
   CONSTRAINT ck_cita_modalidad CHECK (modalidad IN ('Presencial', 'Virtual')),
   CONSTRAINT ck_cita_estado CHECK (
       estado IN ('Programada', 'Confirmada', 'Atendida', 'Cancelada', 'Inasistencia')
   )
);

CREATE TABLE public.informacion_medica (
   id_paciente INTEGER NOT NULL,
   grupo_sanguineo VARCHAR(5),
   situacion_alergias VARCHAR(50),
   detalle_alergias TEXT,
   situacion_enfermedades VARCHAR(50),
   detalle_enfermedades TEXT,

   situacion_medicamentos VARCHAR(50),
   detalle_medicamentos TEXT,
   antecedentes TEXT,
   fecha_actualizacion TIMESTAMPTZ NOT NULL,
   CONSTRAINT pk_informacion_medica PRIMARY KEY (id_paciente),
   CONSTRAINT fk_informacion_medica_paciente FOREIGN KEY (id_paciente)
       REFERENCES public.paciente (id_paciente)
);

CREATE TABLE public.contacto_emergencia (
   id_paciente INTEGER NOT NULL,
   nombre VARCHAR(200) NOT NULL,
   parentesco VARCHAR(50) NOT NULL,
   telefono VARCHAR(30) NOT NULL,
   CONSTRAINT pk_contacto_emergencia PRIMARY KEY (id_paciente),
   CONSTRAINT fk_contacto_emergencia_paciente FOREIGN KEY (id_paciente)
       REFERENCES public.paciente (id_paciente)
);

-- El destino no vacío y el indicador no prueban propiedad ni entrega del correo.
CREATE TABLE public.preferencias_notificacion (
   id_usuario INTEGER NOT NULL,
   recordatorios_habilitados BOOLEAN NOT NULL,
   canal VARCHAR(30) NOT NULL,
   destino VARCHAR(255),
   destino_verificado BOOLEAN NOT NULL,
   CONSTRAINT pk_preferencias_notificacion PRIMARY KEY (id_usuario),

   CONSTRAINT fk_preferencias_notificacion_usuario FOREIGN KEY (id_usuario)
       REFERENCES public.usuario (id_usuario),
   CONSTRAINT ck_notificacion_canal CHECK (canal IN ('CorreoElectronico')),
   CONSTRAINT ck_notificacion_destino_verificado CHECK (
       NOT destino_verificado
       OR (destino IS NOT NULL AND btrim(destino) <> '')
   ),
   CONSTRAINT ck_notificacion_habilitada CHECK (
       NOT recordatorios_habilitados
       OR (destino_verificado AND destino IS NOT NULL AND btrim(destino) <> '')
   )
);

-- Estructura conocida; las opciones funcionales de privacidad siguen pendientes.
CREATE TABLE public.preferencias_privacidad (
   id_preferencia_privacidad INTEGER GENERATED ALWAYS AS IDENTITY NOT NULL,
   fecha_actualizacion TIMESTAMPTZ NOT NULL,
   id_usuario INTEGER NOT NULL,
   CONSTRAINT pk_preferencias_privacidad PRIMARY KEY (id_preferencia_privacidad),
   CONSTRAINT uq_preferencias_privacidad_usuario UNIQUE (id_usuario),
   CONSTRAINT fk_preferencias_privacidad_usuario FOREIGN KEY (id_usuario)
       REFERENCES public.usuario (id_usuario)
);

CREATE TABLE public.registro_atencion (
   id_registro_atencion INTEGER GENERATED ALWAYS AS IDENTITY NOT NULL,
   fecha_registro TIMESTAMPTZ NOT NULL,
   resumen_atencion TEXT NOT NULL,
   diagnostico TEXT,

   indicaciones TEXT,
   id_cita INTEGER NOT NULL,
   id_medico INTEGER NOT NULL,
   CONSTRAINT pk_registro_atencion PRIMARY KEY (id_registro_atencion),
   CONSTRAINT uq_registro_atencion_cita UNIQUE (id_cita),
   CONSTRAINT fk_registro_atencion_cita FOREIGN KEY (id_cita)
       REFERENCES public.cita_medica (id_cita),
   CONSTRAINT fk_registro_atencion_autor FOREIGN KEY (id_medico)
       REFERENCES public.medico (id_medico)
);

CREATE TABLE public.recordatorio_cita (
   id_recordatorio INTEGER GENERATED ALWAYS AS IDENTITY NOT NULL,
   fecha_programada TIMESTAMPTZ NOT NULL,
   fecha_intento TIMESTAMPTZ,
   estado VARCHAR(30) NOT NULL,
   referencia_envio VARCHAR(255),
   id_cita INTEGER NOT NULL,
   CONSTRAINT pk_recordatorio_cita PRIMARY KEY (id_recordatorio),
   CONSTRAINT fk_recordatorio_cita_cita FOREIGN KEY (id_cita)
       REFERENCES public.cita_medica (id_cita),
   CONSTRAINT ck_recordatorio_estado CHECK (
       estado IN ('Pendiente', 'EnProceso', 'AceptadoPorProveedor', 'Fallido',
                  'ResultadoDesconocido', 'Descartado')
   )
);

CREATE TABLE public.evento_cita (
   id_evento INTEGER GENERATED ALWAYS AS IDENTITY NOT NULL,

   tipo VARCHAR(50) NOT NULL,
   fecha_hora TIMESTAMPTZ NOT NULL,
   motivo TEXT,
   id_cita INTEGER NOT NULL,
   id_usuario INTEGER,
   CONSTRAINT pk_evento_cita PRIMARY KEY (id_evento),
   CONSTRAINT fk_evento_cita_cita FOREIGN KEY (id_cita)
       REFERENCES public.cita_medica (id_cita),
   CONSTRAINT fk_evento_cita_usuario FOREIGN KEY (id_usuario)
       REFERENCES public.usuario (id_usuario)
);

-- Índices básicos para FK no cubiertas por el prefijo de una PK o UQ.
-- No constituyen una evaluación de rendimiento ni una optimización validada.
CREATE INDEX ix_usuario_rol ON public.usuario (id_rol);
CREATE INDEX ix_rol_permiso_permiso ON public.rol_permiso (id_permiso);
CREATE INDEX ix_medico_especialidad_especialidad
   ON public.medico_especialidad (id_especialidad);
CREATE INDEX ix_horario_medico ON public.horario_atencion (id_medico);
CREATE INDEX ix_cita_paciente ON public.cita_medica (id_paciente);
CREATE INDEX ix_cita_medico_especialidad
   ON public.cita_medica (id_medico, id_especialidad);
CREATE INDEX ix_cita_especialidad ON public.cita_medica (id_especialidad);
CREATE INDEX ix_registro_atencion_autor ON public.registro_atencion (id_medico);
CREATE INDEX ix_recordatorio_cita ON public.recordatorio_cita (id_cita);
CREATE INDEX ix_evento_cita ON public.evento_cita (id_cita);
CREATE INDEX ix_evento_usuario ON public.evento_cita (id_usuario);

-- Cobro de consulta (Stripe Checkout). Una fila por intento de pago.
CREATE TABLE public.pago_consulta (
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

CREATE INDEX ix_pago_consulta_cita ON public.pago_consulta (id_cita);

-- Consulta administrativa de accesos y cambios. No almacena contraseñas.
CREATE TABLE public.bitacora (
   id_bitacora INTEGER GENERATED ALWAYS AS IDENTITY NOT NULL,
   fecha_hora TIMESTAMPTZ NOT NULL,
   accion VARCHAR(40) NOT NULL,
   modulo VARCHAR(40) NOT NULL,
   detalle TEXT NOT NULL,
   direccion_ip VARCHAR(45),
   actor_nombre VARCHAR(100),
   actor_rol VARCHAR(100),
   id_usuario INTEGER,
   id_registro INTEGER,
   cambios TEXT,
   CONSTRAINT pk_bitacora PRIMARY KEY (id_bitacora),
   CONSTRAINT fk_bitacora_usuario FOREIGN KEY (id_usuario)
       REFERENCES public.usuario (id_usuario),
   CONSTRAINT ck_bitacora_accion CHECK (
       accion IN (
           'Inicio de sesión',
           'Cierre de sesión',
           'Intento fallido',
           'Creación',
           'Actualización',
           'Reprogramación',
           'Cancelación',
           'Desactivación',
           'Activación',
           'Asistencia',
           'Atención'
       )
   )
);

CREATE INDEX ix_bitacora_fecha ON public.bitacora (fecha_hora);
CREATE INDEX ix_bitacora_usuario ON public.bitacora (id_usuario);
