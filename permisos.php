<?php
function normalizarRolUsuario(?string $rol): string {
    $rol = strtolower(trim((string)$rol));

    $alias = [
        'administrador' => 'administrador_general',
        'admin' => 'administrador_general',
        'administrador_general' => 'administrador_general',
        'produccion' => 'produccion',
        'coordinador_produccion' => 'produccion',
        'taller' => 'produccion',
        'ejecutivo_mostrador' => 'ejecutivo_mostrador'
    ];

    return $alias[$rol] ?? $rol;
}

function usuarioTieneAcceso($moduloPermitido = []) {
    if (!isset($_SESSION['rol'])) {
        return false;
    }

    $rol = normalizarRolUsuario($_SESSION['rol']);

    $permisos = [
        'administrador_general' => [
            'dashboard',
            'ventas',
            'clientes',
            'remisiones',
            'imprimir_remision',
            'pedidos',
            'productos',
            'proveedores',
            'configuracion',
            'usuarios',
            'papelera',
            'taller',
            'entregas',
            'produccion',
            'diseno'
        ],
        'ejecutivo_mostrador' => [
            'ventas',
            'clientes',
            'remisiones',
            'imprimir_remision'
        ],
        'produccion' => [
            'pedidos',
            'taller',
            'produccion',
            'diseno'
        ]
    ];

    if (!isset($permisos[$rol])) {
        return false;
    }

    foreach ($moduloPermitido as $modulo) {
        if (in_array($modulo, $permisos[$rol], true)) {
            return true;
        }
    }

    return false;
}

function esAdministradorGeneral() {
    return isset($_SESSION['rol']) && normalizarRolUsuario($_SESSION['rol']) === 'administrador_general';
}

function puedeVerModuloDiseno(): bool {
    if (!isset($_SESSION['rol'])) {
        return false;
    }

    $rol = normalizarRolUsuario($_SESSION['rol']);
    return in_array($rol, ['administrador_general', 'produccion'], true);
}
?>
