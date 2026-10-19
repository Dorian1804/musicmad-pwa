-- Esquema estructural; sin registros de usuarios.

CREATE TABLE `usuarios` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre`       VARCHAR(100) NOT NULL,
  `apellido`     VARCHAR(100) NOT NULL,
  `avatar`       VARCHAR(60)  NOT NULL DEFAULT 'img/avatares/Avatares0.png',
  `bio`          VARCHAR(400) NOT NULL DEFAULT '',
  `color_perfil` VARCHAR(7)   NOT NULL DEFAULT '#d2a679',
  `email`        VARCHAR(180) NOT NULL,
  `REPLACE_WITH_LOCAL_VALUE`     VARCHAR(255) NOT NULL,
  `rol`          ENUM('administrador','cliente') NOT NULL DEFAULT 'cliente',
  `activo`       TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_email` (`email`),
  KEY `idx_rol` (`rol`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `productos` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `nombre`      VARCHAR(120)  NOT NULL,
  `descripcion` VARCHAR(250)  NOT NULL DEFAULT '',
  `precio`      DECIMAL(10,2) NOT NULL,
  `stock`       INT           NOT NULL DEFAULT 0,
  `tipo`        ENUM('servicio','instrumento') NOT NULL,
  `categoria`   VARCHAR(40)   NOT NULL DEFAULT '',
  `imagen_url`  VARCHAR(100)  NOT NULL DEFAULT '',
  `galeria`     VARCHAR(500)  NOT NULL DEFAULT '',
  `activo`      TINYINT(1)    NOT NULL DEFAULT 1,
  `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tipo` (`tipo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `carrito` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `usuario_id`     INT UNSIGNED NOT NULL,
  `fecha_creacion` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_carrito_usuario` (`usuario_id`),
  CONSTRAINT `fk_carrito_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `detalle_carrito` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `carrito_id`  INT UNSIGNED NOT NULL,
  `producto_id` INT UNSIGNED NOT NULL,
  `cantidad`    INT          NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_carrito_producto` (`carrito_id`,`producto_id`),
  KEY `idx_detalle_producto` (`producto_id`),
  CONSTRAINT `fk_detcar_carrito`  FOREIGN KEY (`carrito_id`)  REFERENCES `carrito`   (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_detcar_producto` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `pedidos` (
  `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `usuario_id` INT UNSIGNED  NOT NULL,
  `direccion`  VARCHAR(255)  NOT NULL,
  `telefono`   VARCHAR(30)   NOT NULL,
  `notas`      VARCHAR(500)  NOT NULL DEFAULT '',
  `total`      DECIMAL(10,2) NOT NULL,
  `estado`     ENUM('pendiente','confirmado','enviado','entregado','cancelado') NOT NULL DEFAULT 'pendiente',
  `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pedido_usuario` (`usuario_id`),
  CONSTRAINT `fk_pedido_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
