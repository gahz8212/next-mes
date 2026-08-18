<?php
// backend/routes/api.php
// Registers all RESTful and legacy compatibility routes

// ==========================================
// 1. Modern RESTful Domain Routes
// ==========================================

// Company Routes
Router::get('/companies',          [CompanyController::class, 'getCompanies']);
Router::get('/companies/detail',   [CompanyController::class, 'getCompaniesDetail']);
Router::get('/companies/stats',    [CompanyController::class, 'getCompanyStats']);
Router::post('/companies',         [CompanyController::class, 'createCompany']);
Router::post('/companies/update',  [CompanyController::class, 'updateCompany']);
Router::put('/companies/{id}',     [CompanyController::class, 'updateCompany']);
Router::post('/companies/delete',  [CompanyController::class, 'deleteCompany']);
Router::delete('/companies/{id}',  [CompanyController::class, 'deleteCompany']);

// Item Routes
Router::get('/items',              [ItemController::class, 'getItems']);
Router::post('/items',             [ItemController::class, 'createItem']);
Router::post('/items/update',      [ItemController::class, 'updateItem']);
Router::put('/items/{id}',         [ItemController::class, 'updateItem']);
Router::post('/items/delete',      [ItemController::class, 'deleteItem']);
Router::delete('/items/{id}',      [ItemController::class, 'deleteItem']);

// BOM Routes
Router::get('/bom/masters',          [BomController::class, 'getBomMasters']);
Router::get('/bom/details',          [BomController::class, 'getBomVersionDetails']);
Router::get('/bom/details/{id}',     [BomController::class, 'getBomVersionDetails']);
Router::get('/bom/compare-versions', [BomController::class, 'compareBomVersions']);
Router::post('/bom/compare-versions',[BomController::class, 'compareBomVersions']);
Router::get('/bom',                  [BomController::class, 'getBom']);
Router::post('/bom',                 [BomController::class, 'saveBom']);
Router::post('/bom/delete',          [BomController::class, 'deleteBom']);
Router::delete('/bom/{id}',          [BomController::class, 'deleteBom']);
Router::post('/bom/component',       [BomController::class, 'updateBomComponent']);
Router::post('/bom/compare',         [BomController::class, 'compareBom']);

// Part Alias Routes
Router::get('/part-aliases',       [PartAliasController::class, 'getPartAliases']);
Router::get('/part-aliases/match', [PartAliasController::class, 'matchPartAlias']);
Router::get('/part-aliases/decode',[PartAliasController::class, 'decodeSpec']);
Router::post('/part-aliases/decode',[PartAliasController::class, 'decodeSpec']);
Router::post('/part-aliases/adopt-alternate', [PartAliasController::class, 'adoptAlternate']);
Router::post('/part-aliases',      [PartAliasController::class, 'savePartAlias']);
Router::post('/part-aliases/delete',[PartAliasController::class, 'deletePartAlias']);
Router::delete('/part-aliases/{id}',[PartAliasController::class, 'deletePartAlias']);

// Quality Standards Routes
Router::get('/quality-standards',  [QualityController::class, 'getQualityStandards']);
Router::post('/quality-standards', [QualityController::class, 'saveQualityStandard']);
Router::post('/quality-standards/delete', [QualityController::class, 'deleteQualityStandard']);
Router::delete('/quality-standards/{id}', [QualityController::class, 'deleteQualityStandard']);

// Sales Order Routes
Router::get('/orders',             [OrderController::class, 'getOrders']);
Router::post('/orders',            [OrderController::class, 'createOrder']);
Router::post('/orders/update',     [OrderController::class, 'updateOrder']);
Router::put('/orders/{id}',        [OrderController::class, 'updateOrder']);
Router::post('/orders/delete',     [OrderController::class, 'deleteOrder']);
Router::delete('/orders/{id}',     [OrderController::class, 'deleteOrder']);
Router::post('/orders/convert-wo', [OrderController::class, 'convertOrderToWo']);
Router::post('/orders/{id}/convert-wo', [OrderController::class, 'convertOrderToWo']);
Router::post('/orders/convert-item-wo', [OrderController::class, 'convertOrderItemToWo']);
Router::post('/orders/item/{id}/convert-wo', [OrderController::class, 'convertOrderItemToWo']);

// Work Order Routes
Router::get('/work-orders',        [WorkOrderController::class, 'getWoList']);
Router::get('/work-orders/admin',  [WorkOrderController::class, 'getAdminWoList']);
Router::get('/work-orders/plan',   [WorkOrderController::class, 'getProductionPlan']);
Router::post('/work-orders',       [WorkOrderController::class, 'createWo']);
Router::post('/work-orders/update',[WorkOrderController::class, 'updateWo']);
Router::put('/work-orders/{id}',   [WorkOrderController::class, 'updateWo']);
Router::post('/work-orders/delete',[WorkOrderController::class, 'deleteWo']);
Router::delete('/work-orders/{id}',[WorkOrderController::class, 'deleteWo']);
Router::post('/work-orders/start', [WorkOrderController::class, 'startWo']);
Router::post('/work-orders/start-dip', [WorkOrderController::class, 'startDipWo']);
Router::post('/work-orders/stop',  [WorkOrderController::class, 'stopWo']);
Router::post('/work-orders/switch-line', [WorkOrderController::class, 'switchLineWo']);
Router::post('/work-orders/ship',  [WorkOrderController::class, 'shipWo']);

// Feeder Setup Routes
Router::get('/feeder-setup',       [FeederController::class, 'getFeederSetup']);
Router::get('/feeder-setup/{id}',  [FeederController::class, 'getFeederSetup']);
Router::post('/feeder-setup/scan', [FeederController::class, 'scanFeeder']);
Router::post('/feeder-setup/reset',[FeederController::class, 'resetFeederSetup']);

// Process & Maintenance Routes
Router::post('/process/update',    [ProcessController::class, 'updateProcess']);
Router::get('/defects',            [ProcessController::class, 'getDefects']);
Router::post('/repair',            [ProcessController::class, 'repairBoard']);
Router::post('/line/reset',        [ProcessController::class, 'resetLine']);
Router::post('/maintenance/reset', [ProcessController::class, 'resetMaintenance']);

// Material Routes
Router::get('/materials',          [MaterialController::class, 'getMaterials']);
Router::post('/materials',         [MaterialController::class, 'createMaterial']);
Router::post('/materials/delete',  [MaterialController::class, 'deleteMaterial']);
Router::delete('/materials/{id}',  [MaterialController::class, 'deleteMaterial']);

// Shipment Routes
Router::get('/shipments',          [ShipmentController::class, 'getShipments']);
Router::post('/shipments',         [ShipmentController::class, 'createShipment']);
Router::post('/shipments/status',  [ShipmentController::class, 'updateShipmentStatus']);
Router::put('/shipments/{id}/status', [ShipmentController::class, 'updateShipmentStatus']);

// User Routes
Router::get('/users',              [UserController::class, 'getUsers']);
Router::post('/users',             [UserController::class, 'createUser']);
Router::post('/users/update',      [UserController::class, 'updateUser']);
Router::put('/users/{id}',         [UserController::class, 'updateUser']);
Router::post('/users/delete',      [UserController::class, 'deleteUser']);
Router::delete('/users/{id}',      [UserController::class, 'deleteUser']);

// Dashboard & Logs Routes
Router::get('/dashboard',          [DashboardController::class, 'getDashboard']);
Router::get('/kpi',                [DashboardController::class, 'getKpi']);
Router::get('/kpi-analytics',      [DashboardController::class, 'getKpiAnalytics']);
Router::get('/logs/live',          [DashboardController::class, 'getLiveLogs']);
Router::get('/logs/system',        [DashboardController::class, 'getSystemLogs']);
Router::get('/notifications',      [DashboardController::class, 'getNotifications']);
Router::post('/notifications/read',[DashboardController::class, 'readNotification']);
Router::get('/sse',                [DashboardController::class, 'dashboardSse']);


// ==========================================
// 2. Legacy Endpoint Compatibility Routes
// (Allows old /backend/api/*.php calls to route directly to controllers even without files)
// ==========================================

Router::any('/get_companies.php',          [CompanyController::class, 'getCompanies']);
Router::any('/get_companies_detail.php',   [CompanyController::class, 'getCompaniesDetail']);
Router::any('/get_company_stats.php',      [CompanyController::class, 'getCompanyStats']);
Router::any('/create_company.php',         [CompanyController::class, 'createCompany']);
Router::any('/update_company.php',         [CompanyController::class, 'updateCompany']);
Router::any('/delete_company.php',         [CompanyController::class, 'deleteCompany']);

Router::any('/get_items.php',              [ItemController::class, 'getItems']);
Router::any('/create_item.php',            [ItemController::class, 'createItem']);
Router::any('/update_item.php',            [ItemController::class, 'updateItem']);
Router::any('/delete_item.php',            [ItemController::class, 'deleteItem']);

Router::any('/get_bom.php',                [BomController::class, 'getBom']);
Router::any('/save_bom.php',               [BomController::class, 'saveBom']);
Router::any('/delete_bom.php',             [BomController::class, 'deleteBom']);
Router::any('/update_bom_component.php',   [BomController::class, 'updateBomComponent']);
Router::any('/compare_bom.php',            [BomController::class, 'compareBom']);
Router::any('/get_bom_masters.php',         [BomController::class, 'getBomMasters']);
Router::any('/get_bom_version_details.php', [BomController::class, 'getBomVersionDetails']);
Router::any('/compare_bom_versions.php',    [BomController::class, 'compareBomVersions']);

Router::any('/get_part_aliases.php',       [PartAliasController::class, 'getPartAliases']);
Router::any('/save_part_alias.php',        [PartAliasController::class, 'savePartAlias']);
Router::any('/delete_part_alias.php',      [PartAliasController::class, 'deletePartAlias']);
Router::any('/match_part_alias.php',       [PartAliasController::class, 'matchPartAlias']);
Router::any('/decode_part_spec.php',       [PartAliasController::class, 'decodeSpec']);
Router::any('/adopt_part_alternate.php',   [PartAliasController::class, 'adoptAlternate']);

Router::any('/get_quality_standards.php',  [QualityController::class, 'getQualityStandards']);
Router::any('/save_quality_standard.php',  [QualityController::class, 'saveQualityStandard']);
Router::any('/delete_quality_standard.php',[QualityController::class, 'deleteQualityStandard']);

Router::any('/get_orders.php',             [OrderController::class, 'getOrders']);
Router::any('/create_order.php',           [OrderController::class, 'createOrder']);
Router::any('/update_order.php',           [OrderController::class, 'updateOrder']);
Router::any('/delete_order.php',           [OrderController::class, 'deleteOrder']);
Router::any('/convert_order_to_wo.php',    [OrderController::class, 'convertOrderToWo']);
Router::any('/convert_order_item_to_wo.php', [OrderController::class, 'convertOrderItemToWo']);

Router::any('/get_wo_list.php',            [WorkOrderController::class, 'getWoList']);
Router::any('/get_admin_wo_list.php',      [WorkOrderController::class, 'getAdminWoList']);
Router::any('/create_wo.php',              [WorkOrderController::class, 'createWo']);
Router::any('/update_wo.php',              [WorkOrderController::class, 'updateWo']);
Router::any('/delete_wo.php',              [WorkOrderController::class, 'deleteWo']);
Router::any('/split_work_order.php',       [WorkOrderController::class, 'splitWorkOrder']);
Router::any('/set_wo_hold.php',            [WorkOrderController::class, 'setWorkOrderHold']);
Router::any('/start_wo.php',               [WorkOrderController::class, 'startWo']);
Router::any('/start_dip_wo.php',           [WorkOrderController::class, 'startDipWo']);
Router::any('/stop_wo.php',                [WorkOrderController::class, 'stopWo']);
Router::any('/switch_line_wo.php',         [WorkOrderController::class, 'switchLineWo']);
Router::any('/ship_wo.php',                [WorkOrderController::class, 'shipWo']);
Router::any('/get_production_plan.php',    [WorkOrderController::class, 'getProductionPlan']);

Router::any('/get_feeder_setup.php',       [FeederController::class, 'getFeederSetup']);
Router::any('/scan_feeder.php',            [FeederController::class, 'scanFeeder']);
Router::any('/reset_feeder_setup.php',     [FeederController::class, 'resetFeederSetup']);

Router::any('/update_process.php',         [ProcessController::class, 'updateProcess']);
Router::any('/get_defects.php',            [ProcessController::class, 'getDefects']);
Router::any('/repair_board.php',           [ProcessController::class, 'repairBoard']);
Router::any('/reset_line.php',             [ProcessController::class, 'resetLine']);
Router::any('/reset_maintenance.php',      [ProcessController::class, 'resetMaintenance']);

Router::any('/get_materials.php',          [MaterialController::class, 'getMaterials']);
Router::any('/create_material.php',        [MaterialController::class, 'createMaterial']);
Router::any('/delete_material.php',        [MaterialController::class, 'deleteMaterial']);

Router::any('/get_consigned_summary.php',       [ConsignedMaterialController::class, 'getSummary']);
Router::any('/get_consigned_stocks.php',        [ConsignedMaterialController::class, 'getStockList']);
Router::any('/get_bom_parts_for_order.php',     [ConsignedMaterialController::class, 'getBomPartsForOrder']);
Router::any('/receive_consigned_materials.php', [ConsignedMaterialController::class, 'receiveBatch']);
Router::any('/get_consigned_reconciliation.php',[ConsignedMaterialController::class, 'getReconciliation']);
Router::any('/create_consigned_return.php',     [ConsignedMaterialController::class, 'createReturn']);
Router::any('/get_consigned_returns.php',       [ConsignedMaterialController::class, 'getReturnList']);
Router::any('/get_consigned_return_detail.php', [ConsignedMaterialController::class, 'getReturnDetail']);

Router::any('/get_shipments.php',          [ShipmentController::class, 'getShipments']);
Router::any('/create_shipment.php',        [ShipmentController::class, 'createShipment']);
Router::any('/update_shipment_status.php', [ShipmentController::class, 'updateShipmentStatus']);

Router::any('/get_users.php',              [UserController::class, 'getUsers']);
Router::any('/create_user.php',            [UserController::class, 'createUser']);
Router::any('/update_user.php',            [UserController::class, 'updateUser']);
Router::any('/delete_user.php',            [UserController::class, 'deleteUser']);

Router::any('/get_dashboard.php',          [DashboardController::class, 'getDashboard']);
Router::any('/get_kpi.php',                [DashboardController::class, 'getKpi']);
Router::any('/get_kpi_analytics.php',      [DashboardController::class, 'getKpiAnalytics']);
Router::any('/get_live_logs.php',          [DashboardController::class, 'getLiveLogs']);
Router::any('/get_system_logs.php',        [DashboardController::class, 'getSystemLogs']);
Router::any('/get_notifications.php',      [DashboardController::class, 'getNotifications']);
Router::any('/read_notification.php',      [DashboardController::class, 'readNotification']);
Router::any('/dashboard_sse.php',          [DashboardController::class, 'dashboardSse']);
