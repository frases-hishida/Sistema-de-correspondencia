<?php
/* =====================================================================
   TRÁMITE — Sistema de Correspondencia (archivo único + SQLite)
   CERO DEPENDENCIAS: solo PHP con pdo_sqlite (viene por defecto).
   Sin XAMPP, sin MySQL. La BD se crea sola en tramite.db.
   ===================================================================== */
ob_start();
error_reporting(E_ALL); ini_set('display_errors','1');
session_start();

/* ---------- BD SQLITE (se crea sola) ---------- */
try{
  $pdo = new PDO('sqlite:'.__DIR__.'/tramite.db');
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
  $pdo->exec("PRAGMA journal_mode=WAL");
}catch(PDOException $ex){
  while(ob_get_level()) ob_end_clean();
  die('<div style="font-family:sans-serif;max-width:560px;margin:60px auto;padding:24px;border:1px solid #ddd;border-radius:12px">
  <h2>No se pudo abrir la base de datos</h2><p>'.htmlspecialchars($ex->getMessage()).'</p>
  <p>Verifica permiso de escritura en la carpeta y la extensi\u00f3n <b>pdo_sqlite</b> de PHP.</p></div>');
}

 $q = function($sql,$params=[]) use ($pdo){ $st=$pdo->prepare($sql); $st->execute($params); return $st; };
function now(){ return date('Y-m-d H:i:s'); }

/* ---------- ESQUEMA (SQLite) ---------- */
 $pdo->exec("CREATE TABLE IF NOT EXISTS offices (
  id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, code TEXT NOT NULL UNIQUE)");
 $pdo->exec("CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT NOT NULL UNIQUE,
  password TEXT NOT NULL, role TEXT DEFAULT 'operador',
  office_id INTEGER NOT NULL, position TEXT, active INTEGER DEFAULT 1,
  reset_question TEXT, reset_answer TEXT)");
 $pdo->exec("CREATE TABLE IF NOT EXISTS documents (
  id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, office_id INTEGER NOT NULL,
  type TEXT NOT NULL, cite_number TEXT NOT NULL,
  subject TEXT NOT NULL, file_path TEXT,
  status TEXT DEFAULT 'borrador', created_at TEXT)");
 $pdo->exec("CREATE TABLE IF NOT EXISTS routes (
  id INTEGER PRIMARY KEY AUTOINCREMENT, tracking_number TEXT NOT NULL UNIQUE,
  creator_user_id INTEGER NOT NULL, current_office_id INTEGER,
  type TEXT DEFAULT 'sin_cite', document_id INTEGER,
  subject TEXT NOT NULL, pages_count INTEGER DEFAULT 1, annex_count INTEGER DEFAULT 0,
  attachments TEXT, priority TEXT DEFAULT 'normal',
  status TEXT DEFAULT 'ENVIADO',
  archive_folder TEXT, archive_note TEXT,
  created_at TEXT)");
 $pdo->exec("CREATE TABLE IF NOT EXISTS route_derivations (
  id INTEGER PRIMARY KEY AUTOINCREMENT, route_id INTEGER NOT NULL,
  sender_user_id INTEGER NOT NULL, receiver_user_id INTEGER NOT NULL,
  sender_office_id INTEGER NOT NULL, receiver_office_id INTEGER NOT NULL,
  instruction TEXT NOT NULL, attach_document_id INTEGER,
  is_copy INTEGER DEFAULT 0, sent_at TEXT,
  received_at TEXT, finished_at TEXT, cancelled INTEGER DEFAULT 0)");
 $pdo->exec("CREATE TABLE IF NOT EXISTS route_groups (
  id INTEGER PRIMARY KEY AUTOINCREMENT, parent_route_id INTEGER NOT NULL,
  child_route_id INTEGER NOT NULL, created_by INTEGER NOT NULL,
  created_at TEXT)");
try{ $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS uniq_child ON route_groups(child_route_id)"); }catch(Exception $e){}
try{ $pdo->exec("CREATE INDEX IF NOT EXISTS ix_recv ON route_derivations(receiver_user_id,is_copy,received_at)"); }catch(Exception $e){}
try{ $pdo->exec("CREATE INDEX IF NOT EXISTS ix_route ON route_derivations(route_id)"); }catch(Exception $e){}

if(!is_dir(__DIR__.'/uploads')) @mkdir(__DIR__.'/uploads',0777,true);

/* Normaliza sesión */
if(isset($_SESSION['user'])){
  if(!is_array($_SESSION['user']) || empty($_SESSION['user']['id'])) unset($_SESSION['user']);
  else{
    $_SESSION['user']['id']        = (int)$_SESSION['user']['id'];
    $_SESSION['user']['office_id'] = (int)($_SESSION['user']['office_id'] ?? 0);
    $_SESSION['user']['role']      = $_SESSION['user']['role'] ?? 'operador';
    $_SESSION['user']['name']      = $_SESSION['user']['name'] ?? '';
    $_SESSION['user']['position']  = $_SESSION['user']['position'] ?? '';
  }
}

/* ---------- IDIOMA ---------- */
if(isset($_GET['lang'])){
  $l=$_GET['lang'];
  if(in_array($l,['es','en'],true)){ setcookie('lang',$l,time()+31536000,'/'); $_COOKIE['lang']=$l; }
  $uri=preg_replace('/([?&])lang=[^&]*&?/','$1',$_SERVER['REQUEST_URI']);
  header('Location: '.rtrim($uri,'?&')); exit;
}
 $LANG = in_array($_COOKIE['lang']??'',['es','en'],true) ? $_COOKIE['lang'] : 'es';

 $I18N = [
'es'=>[
 'tag'=>'Correspondencia · Hojas de ruta','nav_dash'=>'Panel','nav_new'=>'Nueva hoja de ruta','nav_cites'=>'Cites',
 'nav_users'=>'Usuarios','nav_offices'=>'Oficinas','nav_inbox'=>'Entrantes','nav_pending'=>'Pendientes',
 'nav_sent'=>'Enviados','nav_archive'=>'Archivo','nav_admin'=>'Administración','nav_oper'=>'Operar','nav_trays'=>'Bandejas',
 'logout'=>'Cerrar sesión','search_ph'=>'Buscar por hoja de ruta, cite o referencia…',
 'login_title'=>'Bienvenido de nuevo','login_sub'=>'Ingrese con sus credenciales institucionales.','login_btn'=>'Ingresar',
 'tab_login'=>'Ingresar','tab_register'=>'Crear cuenta','forgot'=>'¿Olvidó su contraseña?',
 'email'=>'Correo institucional','password'=>'Contraseña','new_pass'=>'Nueva contraseña','confirm'=>'Confirmar',
 'reg_title'=>'Crear cuenta','reg_sub'=>'Se creará con rol <b>operador</b> y acceso inmediato.','reg_btn'=>'Crear mi cuenta',
 'name_full'=>'Nombre completo','office'=>'Oficina','position'=>'Cargo','pw_keep'=>'(vacío = no cambiar)',
 'reg_question'=>'Pregunta de seguridad (para recuperar su clave)','reg_answer'=>'Respuesta','answer_ph'=>'Su respuesta (no distingue mayúsculas)',
 'reg_no_offices'=>'Aún no hay oficinas registradas. Solicite al administrador que cree al menos una antes de registrarse.',
 'reg_back'=>'Volver a ingresar','foot_reg'=>'¿Primera vez aquí?','foot_reg2'=>'Cree su cuenta','foot_reg3'=>'o contacte al administrador.',
 'foot_login'=>'¿Ya tiene cuenta?','foot_login2'=>'Ingrese aquí','foot_rec'=>'¿Recuperó el acceso?','foot_rec2'=>'Volver al ingreso',
 'rec1_t'=>'Recuperar acceso','rec1_s'=>'Escriba su correo y le mostraremos su pregunta de seguridad.','rec1_btn'=>'Continuar',
 'rec2_t'=>'Verificación','rec2_s'=>'Cuenta:','rec2_btn'=>'Verificar','rec_cancel'=>'Cancelar y volver',
 'rec3_t'=>'Nueva contraseña','rec3_s'=>'Verificación superada. Defina su nueva clave.','rec3_btn'=>'Guardar contraseña',
 'inst_t'=>'Primera instalación','inst_s'=>'Cree la <b>oficina principal</b> y su usuario <b>administrador</b>.',
 'inst_office'=>'Oficina principal','inst_admin'=>'Administrador','inst_btn'=>'Crear administrador',
 'hello'=>'Hola,','by_receive'=>'por recibir','dash_action'=>'Requieren su acción','dash_entry'=>'Bandeja de entrada',
 'dash_ok_t'=>'Todo en orden','dash_ok_p'=>'No hay trámites pendientes.','sent_by'=>'Enviada por','in_respons'=>'En trámite bajo su responsabilidad',
 'created_by_you'=>'Creadas por usted','cites_issued'=>'Cites emitidos','week_moves'=>'Movimientos · 7 días',
 'act_recent'=>'Actividad reciente','act_empty_t'=>'Sin actividad','act_empty_p'=>'Aún no hay movimientos.',
 'act_sent_to'=>'Enviada a','act_you_sent'=>'le envió',
 'inbox_h'=>'Entrantes','inbox_p'=>'Trámites que aguardan su confirmación de recepción física.','to_receive'=>'Por recibir',
 'inbox_empty_t'=>'Nada por recibir','inbox_empty_p'=>'Cuando alguien le envíe una hoja de ruta aparecerá aquí.',
 'copies'=>'Copias informativas','copies_empty_t'=>'Sin copias','copies_empty_p'=>'Las copias no generan responsabilidad de trámite.',
 'copy_of'=>'Copia de','from'=>'De','sheets'=>'hoja(s)','view'=>'Ver','receive'=>'Recibir',
 'pend_h'=>'Pendientes','pend_p'=>'Trámites bajo su responsabilidad, con contador de días hábiles.',
 'group_btn'=>'Agrupar seleccionadas','pend_empty_t'=>'Sin pendientes','pend_empty_p'=>'Los trámites recibidos y no concluidos se listan aquí.',
 'grouped_to'=>'Agrupada →','received_on'=>'Recibida',
 'group_h'=>'Agrupar en expediente','group_s'=>'Seleccione la hoja que actuará como <b>carátula</b>. Las demás quedarán vinculadas.',
 'group_create'=>'Crear expediente','cancel'=>'Cancelar',
 'sent_h'=>'Enviados','sent_p'=>'Puede cancelar un envío mientras no haya sido recibido.',
 'sent_empty_t'=>'Nada enviado aún','sent_empty_p'=>'Cree una hoja de ruta para comenzar.','to_user'=>'Para',
 'st_transit'=>'En tránsito','st_received'=>'Recibida','st_done'=>'Concluida','st_cancel'=>'Cancelada',
 'arch_h'=>'Archivo','arch_p'=>'Trámites concluidos bajo su custodia. Puede reabrirlos.','arch_empty_t'=>'Archivo vacío',
 'arch_empty_p'=>'Los trámites que archive se conservan aquí.','unarchive'=>'Desarchivar','archived_on'=>'Archivada',
 'new_h'=>'Nueva hoja de ruta','new_s'=>'Al guardar, el trámite se envía automáticamente con número único de seguimiento.',
 'type'=>'Tipo','no_cite'=>'Sin cite','with_cite'=>'Con cite','cite_doc'=>'Documento con cite','subject'=>'Referencia / asunto',
 'subject_ph'=>'Síntesis del trámite…','pages'=>'Cantidad de hojas','annexes'=>'Anexos','priority'=>'Prioridad',
 'prio_n'=>'Normal · 5 d','prio_u'=>'Urgente · 2 d','dest'=>'Destinatario','dest_ph'=>'— Seleccionar —',
 'instruction'=>'Proveído (instrucción)','instr_ph'=>'Ej.: Para su atención…','copies_opt'=>'Copias informativas (opcional)',
 'attach_files'=>'Archivos adjuntos (máx. 5 MB c/u)','attach_hint'=>'Haga clic para adjuntar documentos',
 'save_send'=>'Guardar y enviar','receipt'=>'Comprobante a emitir','receipt_note'=>'Número único de hoja de ruta, rastreable desde el buscador.',
 'only_you'=>'Por ahora usted es el único usuario: puede enviársela a usted mismo para probar el flujo, o crear más usuarios en <b>Administración → Usuarios</b>.',
 'no_users_a'=>'No hay usuarios activos. Créalos en <b>Administración → Usuarios</b>.',
 'no_users_u'=>'No hay usuarios activos. Pida al administrador que los cree.',
 'pv1'=>'Para su atención.','pv2'=>'Para su conocimiento.','pv3'=>'Para su análisis y dictamen.','pv4'=>'Para su trámite y archivo.',
 'derive_h'=>'Derivar hoja de ruta','derive_to'=>'Derivar a','new_prov'=>'Nuevo proveído','attach_own'=>'Adjuntar cite propio (opcional)',
 'no_doc'=>'— Sin documento —','send_copy'=>'Enviar copia a (opcional)','derive_btn'=>'Derivar',
 'arch_f_t'=>'Archivar trámite','folder'=>'Carpeta de archivo','close_note'=>'Observación de cierre',
 'close_ph'=>'Condiciones, cargo, referencia física…','arch_btn'=>'Concluir y archivar',
 'fa1'=>'Archivo Gestión ','fa2'=>'Legajos de Comisiones','fa3'=>'Archivo Histórico','fa4'=>'Correspondencia Externa',
 'created_by'=>'Creada por','document_lbl'=>'Documento','docum_lbl'=>'Documentación','emission'=>'Emisión',
 'folder_s'=>'Carpeta','note_s'=>'Observación','expediente'=>'Expediente','exp_group'=>'Expediente agrupador',
 'linked'=>'vinculada(s)','daughter'=>'Hija','attachment'=>'Adjunto','trace_lbl'=>'Trazabilidad de la hoja de ruta',
 'print_btn'=>'Imprimir constancia','await'=>'Aguardando recepción','recv_at'=>'Recibida','now_held'=>'en poder actualmente',
 'held_for'=>'en poder','cancel_note'=>'Envío cancelado por el remitente','copy_to'=>'Copia informativa →',
 'ack_at'=>'Acuse recibido','no_ack'=>'Sin acuse aún','cite_attached'=>'Adjunta cite:',
 'cites_h'=>'Cites','cites_p'=>'Documentos con correlativo de su oficina. Genere, edite en Word y suba el final (máx. 5 MB).',
 'gen_cite'=>'Generar nuevo cite','doc_type'=>'Tipo de documento','informe'=>'Informe','nota_int'=>'Nota interna','nota_ext'=>'Nota externa',
 'subject_c'=>'Asunto','subject_ph2'=>'Ej.: Informe sobre…','gen_btn'=>'Generar cite','your_docs'=>'Sus documentos',
 'cites_empty_t'=>'Sin cites','cites_empty_p'=>'Genere su primer documento para crear hojas de ruta "con cite".',
 'template'=>'Plantilla','upload_final'=>'Subir final','file_loaded'=>'Archivo cargado','no_file'=>'Sin archivo final',
 'search_h'=>'Búsqueda universal','results_for'=>'Resultados para','search_empty_t'=>'Sin resultados',
 'search_empty_p'=>'Pruebe con hoja de ruta, cite, referencia o remitente.','made_by'=>'Creada por',
 'users_h'=>'Usuarios','users_p'=>'Cree usuarios y asigne su rol: <b>admin</b>, <b>jefe</b> u <b>operador</b>.',
 'nu_t'=>'Nuevo usuario','eu_t'=>'Editar usuario','role'=>'Rol','email_login'=>'Correo (login)',
 'active_user'=>'Usuario activo','save_c'=>'Guardar cambios','create_u'=>'Crear usuario','users_list'=>'Usuarios registrados',
 'users_empty_t'=>'Sin usuarios','users_empty_p'=>'Cree el primero con el formulario de arriba.','inactive'=>'Inactivo','you'=>'Usted',
 'off_h'=>'Oficinas','off_p'=>'El código se usa en los correlativos de cites y hojas de ruta.','no_t'=>'Nueva oficina',
 'eo_t'=>'Editar oficina','off_name'=>'Nombre de la oficina','code'=>'CÓDIGO','add'=>'Agregar','save'=>'Guardar',
 'off_list'=>'Oficinas registradas','off_empty_t'=>'Sin oficinas','off_empty_p'=>'Cree la primera con el formulario de arriba.',
 'n_users'=>'usuario(s)',
 'sla_over'=>'Vencido %d d','sla_today'=>'Vence hoy','sla_left'=>'%d d hábiles',
 'q1'=>'¿Cómo se llamaba su primera mascota?','q2'=>'¿En qué ciudad nació usted?','q3'=>'¿Cuál era el nombre de su profesor favorito?',
 'q4'=>'¿Cuál es el segundo nombre de su madre?','q5'=>'¿Cuál era su comida de infancia favorita?',
 'm_received'=>'Trámite recibido. Pasó a <b>Pendientes</b>.','m_no_recv'=>'Ese envío ya no está disponible para recibir.',
 'm_hr_sent'=>'Hoja de ruta <b>%s</b> enviada a <b>%s</b>.','m_need'=>'Complete: referencia, destinatario y proveído.',
 'm_need_cite'=>'Complete: referencia, destinatario, proveído y un cite válido.','m_derived'=>'Derivada a <b>%s</b>.',
 'm_derive_fail'=>'No se pudo derivar: complete destinatario y proveído.','m_arch_fail'=>'No se pudo archivar: la observación es obligatoria.',
 'm_archived'=>'Archivado en <b>%s</b>.','m_unarch'=>'Trámite reabierto. Volvió a <b>Pendientes</b>.',
 'm_cancel_ok'=>'Envío cancelado antes de su recepción.','m_cancel_fail'=>'Ya no se puede cancelar: fue recibido o anulado.',
 'm_grp_need'=>'Seleccione al menos dos hojas de ruta.','m_grp_none'=>'Las hojas seleccionadas no están disponibles para agrupar.',
 'm_grp_ok'=>'Expediente creado: %d hoja(s) vinculada(s).','m_grp_zero'=>'No se vinculó ninguna hoja nueva.',
 'm_grp_exp'=>'Sesión de agrupación expirada: seleccione nuevamente.',
 'm_cite_ok'=>'Cite <b>%s</b> generado. Descargue la plantilla.','m_cite_need'=>'El asunto es obligatorio.',
 'm_cite_5m'=>'Un archivo supera el límite de 5 MB.','m_cite_up'=>'Documento final vinculado a <b>%s</b>.','m_cite_bad'=>'Seleccione un archivo válido.',
 'm_user_ok'=>'Usuario <b>%s</b> creado (rol %s).','m_user_upd'=>'Usuario actualizado.','m_user_selfoff'=>'No puede desactivar su propia cuenta.',
 'm_user_state'=>'Estado actualizado.','m_user_selfdel'=>'No puede eliminar su propia cuenta.','m_user_del'=>'Usuario eliminado.',
 'm_user_has'=>'Tiene trámites asociados: desactívelo.','m_off_ok'=>'Oficina creada.','m_off_upd'=>'Oficina actualizada.',
 'm_off_dup'=>'El código ya existe.','m_off_need'=>'Nombre y código obligatorios.','m_off_del'=>'Oficina eliminada.',
 'm_off_has'=>'No se puede eliminar: tiene usuarios o trámites.',
 'm_login_fail'=>'Credenciales incorrectas.','m_csrf'=>'Sesión de formulario expirada. Intente de nuevo.',
 'm_admin_only'=>'Solo el administrador accede aquí.','m_inst_ok'=>'Administrador creado. Ahora ingrese con sus credenciales.',
 'e_name'=>'El nombre es obligatorio.','e_email'=>'Email inválido.','e_passlen'=>'La contraseña debe tener al menos 6 caracteres.',
 'e_passmatch'=>'Las contraseñas no coinciden.','e_emaildup'=>'Ese correo ya está registrado.','e_office'=>'Seleccione una oficina válida.',
 'e_question'=>'Seleccione una pregunta de seguridad.','e_answer'=>'Escriba la respuesta de seguridad.',
 'e_office_fill'=>'Complete nombre y código de la oficina.','m_reg_ok'=>'Cuenta creada como <b>operador</b>. Ya puede ingresar.',
 'm_rec_noacc'=>'No existe una cuenta activa con ese correo.','m_rec_noq'=>'Su cuenta no tiene pregunta de recuperación. Contacte al administrador.',
 'm_rec_bad'=>'Respuesta incorrecta. Intente de nuevo.','m_rec_okans'=>'Respuesta correcta. Defina su nueva contraseña.',
 'm_rec_done'=>'Contraseña actualizada. Ingrese con su nueva clave.',
 'wt_body'=>'Tengo el honor de dirigirme a usted para informar… <i>(edite este documento y súbalo finalizado desde el módulo Cites)</i>',
 'wt_close'=>'Es todo cuanto se informa a su consideración.','wt_dear'=>'Señor/a:','wt_present'=>'Presente.-','wt_ref'=>'Ref.:',
],
'en'=>[
 'tag'=>'Correspondence · Routing slips','nav_dash'=>'Dashboard','nav_new'=>'New routing slip','nav_cites'=>'Cites',
 'nav_users'=>'Users','nav_offices'=>'Offices','nav_inbox'=>'Inbox','nav_pending'=>'Pending',
 'nav_sent'=>'Sent','nav_archive'=>'Archive','nav_admin'=>'Administration','nav_oper'=>'Operate','nav_trays'=>'Trays',
 'logout'=>'Sign out','search_ph'=>'Search by slip number, cite or subject…',
 'login_title'=>'Welcome back','login_sub'=>'Sign in with your institutional credentials.','login_btn'=>'Sign in',
 'tab_login'=>'Sign in','tab_register'=>'Create account','forgot'=>'Forgot your password?',
 'email'=>'Institutional email','password'=>'Password','new_pass'=>'New password','confirm'=>'Confirm',
 'reg_title'=>'Create account','reg_sub'=>'It will be created with the <b>operator</b> role and immediate access.','reg_btn'=>'Create my account',
 'name_full'=>'Full name','office'=>'Office','position'=>'Position','pw_keep'=>'(empty = keep current)',
 'reg_question'=>'Security question (to recover your password)','reg_answer'=>'Answer','answer_ph'=>'Your answer (case-insensitive)',
 'reg_no_offices'=>'No offices registered yet. Ask the administrator to create one before signing up.',
 'reg_back'=>'Back to sign in','foot_reg'=>'First time here?','foot_reg2'=>'Create your account','foot_reg3'=>'or contact the administrator.',
 'foot_login'=>'Already have an account?','foot_login2'=>'Sign in here','foot_rec'=>'Access recovered?','foot_rec2'=>'Back to sign in',
 'rec1_t'=>'Recover access','rec1_s'=>'Enter your email and we will show your security question.','rec1_btn'=>'Continue',
 'rec2_t'=>'Verification','rec2_s'=>'Account:','rec2_btn'=>'Verify','rec_cancel'=>'Cancel and go back',
 'rec3_t'=>'New password','rec3_s'=>'Verification passed. Set your new password.','rec3_btn'=>'Save password',
 'inst_t'=>'First-time setup','inst_s'=>'Create the <b>main office</b> and your <b>administrator</b> account.',
 'inst_office'=>'Main office','inst_admin'=>'Administrator','inst_btn'=>'Create administrator',
 'hello'=>'Hello,','by_receive'=>'to receive','dash_action'=>'Needs your action','dash_entry'=>'Inbox',
 'dash_ok_t'=>'All clear','dash_ok_p'=>'No pending items.','sent_by'=>'Sent by','in_respons'=>'In progress under your responsibility',
 'created_by_you'=>'Created by you','cites_issued'=>'Cites issued','week_moves'=>'Activity · 7 days',
 'act_recent'=>'Recent activity','act_empty_t'=>'No activity','act_empty_p'=>'No movements yet.',
 'act_sent_to'=>'Sent to','act_you_sent'=>'sent you',
 'inbox_h'=>'Inbox','inbox_p'=>'Items awaiting your physical receipt confirmation.','to_receive'=>'To receive',
 'inbox_empty_t'=>'Nothing to receive','inbox_empty_p'=>'When someone sends you a routing slip it will appear here.',
 'copies'=>'Informational copies','copies_empty_t'=>'No copies','copies_empty_p'=>'Copies do not create processing liability.',
 'copy_of'=>'Copy from','from'=>'From','sheets'=>'sheet(s)','view'=>'View','receive'=>'Receive',
 'pend_h'=>'Pending','pend_p'=>'Items under your responsibility, with business-day counter.',
 'group_btn'=>'Group selected','pend_empty_t'=>'No pending items','pend_empty_p'=>'Received, unfinished items are listed here.',
 'grouped_to'=>'Grouped →','received_on'=>'Received',
 'group_h'=>'Group into a file','group_s'=>'Pick the slip that will be the <b>cover</b>. The rest will be linked to it.',
 'group_create'=>'Create file','cancel'=>'Cancel',
 'sent_h'=>'Sent','sent_p'=>'You can cancel a delivery before it is received.',
 'sent_empty_t'=>'Nothing sent yet','sent_empty_p'=>'Create a routing slip to get started.','to_user'=>'To',
 'st_transit'=>'In transit','st_received'=>'Received','st_done'=>'Concluded','st_cancel'=>'Cancelled',
 'arch_h'=>'Archive','arch_p'=>'Concluded items in your custody. You can reopen them.','arch_empty_t'=>'Empty archive',
 'arch_empty_p'=>'Archived items are kept here.','unarchive'=>'Unarchive','archived_on'=>'Archived',
 'new_h'=>'New routing slip','new_s'=>'When saved, the item is sent automatically with a unique tracking number.',
 'type'=>'Type','no_cite'=>'Without cite','with_cite'=>'With cite','cite_doc'=>'Cited document','subject'=>'Subject',
 'subject_ph'=>'Brief description…','pages'=>'Page count','annexes'=>'Annexes','priority'=>'Priority',
 'prio_n'=>'Normal · 5 d','prio_u'=>'Urgent · 2 d','dest'=>'Recipient','dest_ph'=>'— Select —',
 'instruction'=>'Instruction (proveído)','instr_ph'=>'E.g.: For your attention…','copies_opt'=>'Informational copies (optional)',
 'attach_files'=>'Attachments (max 5 MB each)','attach_hint'=>'Click to attach documents',
 'save_send'=>'Save and send','receipt'=>'Receipt to be issued','receipt_note'=>'Unique slip number, searchable from the search bar.',
 'only_you'=>'You are currently the only user: you can send it to yourself to test the flow, or create more users in <b>Administration → Users</b>.',
 'no_users_a'=>'No active users. Create them in <b>Administration → Users</b>.',
 'no_users_u'=>'No active users. Ask the administrator to create them.',
 'pv1'=>'For your attention.','pv2'=>'For your knowledge.','pv3'=>'For analysis and opinion.','pv4'=>'For processing and filing.',
 'derive_h'=>'Route item onward','derive_to'=>'Route to','new_prov'=>'New instruction','attach_own'=>'Attach own cite (optional)',
 'no_doc'=>'— No document —','send_copy'=>'Send copy to (optional)','derive_btn'=>'Route',
 'arch_f_t'=>'Archive item','folder'=>'Archive folder','close_note'=>'Closing note',
 'close_ph'=>'Conditions, receipt, physical reference…','arch_btn'=>'Conclude and archive',
 'fa1'=>'Records ','fa2'=>'Committee files','fa3'=>'Historical archive','fa4'=>'External correspondence',
 'created_by'=>'Created by','document_lbl'=>'Document','docum_lbl'=>'Paperwork','emission'=>'Issued',
 'folder_s'=>'Folder','note_s'=>'Note','expediente'=>'File','exp_group'=>'Grouping file',
 'linked'=>'linked','daughter'=>'Child','attachment'=>'Attachment','trace_lbl'=>'Routing slip traceability',
 'print_btn'=>'Print receipt','await'=>'Awaiting receipt','recv_at'=>'Received','now_held'=>'currently held',
 'held_for'=>'held for','cancel_note'=>'Delivery cancelled by sender','copy_to'=>'Informational copy →',
 'ack_at'=>'Acknowledged','no_ack'=>'Not acknowledged yet','cite_attached'=>'Attached cite:',
 'cites_h'=>'Cites','cites_p'=>'Documents numbered per your office. Generate, edit in Word and upload the final (max 5 MB).',
 'gen_cite'=>'Generate new cite','doc_type'=>'Document type','informe'=>'Report','nota_int'=>'Internal note','nota_ext'=>'External note',
 'subject_c'=>'Subject','subject_ph2'=>'E.g.: Report on…','gen_btn'=>'Generate cite','your_docs'=>'Your documents',
 'cites_empty_t'=>'No cites','cites_empty_p'=>'Generate your first document to create "with cite" routing slips.',
 'template'=>'Template','upload_final'=>'Upload final','file_loaded'=>'File uploaded','no_file'=>'No final file',
 'search_h'=>'Universal search','results_for'=>'Results for','search_empty_t'=>'No results',
 'search_empty_p'=>'Try slip number, cite, subject or sender.','made_by'=>'Created by',
 'users_h'=>'Users','users_p'=>'Create users and assign roles: <b>admin</b>, <b>chief</b> or <b>operator</b>.',
 'nu_t'=>'New user','eu_t'=>'Edit user','role'=>'Role','email_login'=>'Email (login)',
 'active_user'=>'Active user','save_c'=>'Save changes','create_u'=>'Create user','users_list'=>'Registered users',
 'users_empty_t'=>'No users','users_empty_p'=>'Create the first one with the form above.','inactive'=>'Inactive','you'=>'You',
 'off_h'=>'Offices','off_p'=>'The code is used in cite and slip numbering.','no_t'=>'New office',
 'eo_t'=>'Edit office','off_name'=>'Office name','code'=>'CODE','add'=>'Add','save'=>'Save',
 'off_list'=>'Registered offices','off_empty_t'=>'No offices','off_empty_p'=>'Create the first one with the form above.',
 'n_users'=>'user(s)',
 'sla_over'=>'%d d overdue','sla_today'=>'Due today','sla_left'=>'%d business days',
 'q1'=>"What was your first pet's name?",'q2'=>'In what city were you born?','q3'=>'Who was your favorite teacher?',
 'q4'=>"What is your mother's second name?",'q5'=>'What was your favorite childhood food?',
 'm_received'=>'Item received. Moved to <b>Pending</b>.','m_no_recv'=>'That delivery is no longer available to receive.',
 'm_hr_sent'=>'Routing slip <b>%s</b> sent to <b>%s</b>.','m_need'=>'Complete: subject, recipient and instruction.',
 'm_need_cite'=>'Complete: subject, recipient, instruction and a valid cite.','m_derived'=>'Routed to <b>%s</b>.',
 'm_derive_fail'=>'Could not route: complete recipient and instruction.','m_arch_fail'=>'Could not archive: the closing note is required.',
 'm_archived'=>'Archived in <b>%s</b>.','m_unarch'=>'Item reopened. Back to <b>Pending</b>.',
 'm_cancel_ok'=>'Delivery cancelled before receipt.','m_cancel_fail'=>'Can no longer be cancelled: received or voided.',
 'm_grp_need'=>'Select at least two routing slips.','m_grp_none'=>'Selected slips are not available for grouping.',
 'm_grp_ok'=>'File created: %d slip(s) linked.','m_grp_zero'=>'No new slips were linked.',
 'm_grp_exp'=>'Grouping session expired: select again.',
 'm_cite_ok'=>'Cite <b>%s</b> generated. Download the template.','m_cite_need'=>'Subject is required.',
 'm_cite_5m'=>'A file exceeds the 5 MB limit.','m_cite_up'=>'Final document linked to <b>%s</b>.','m_cite_bad'=>'Select a valid file.',
 'm_user_ok'=>'User <b>%s</b> created (role %s).','m_user_upd'=>'User updated.','m_user_selfoff'=>'You cannot deactivate your own account.',
 'm_user_state'=>'Status updated.','m_user_selfdel'=>'You cannot delete your own account.','m_user_del'=>'User deleted.',
 'm_user_has'=>'Has linked items: deactivate instead.','m_off_ok'=>'Office created.','m_off_upd'=>'Office updated.',
 'm_off_dup'=>'Code already exists.','m_off_need'=>'Name and code are required.','m_off_del'=>'Office deleted.',
 'm_off_has'=>'Cannot delete: it has users or items.',
 'm_login_fail'=>'Incorrect credentials.','m_csrf'=>'Form session expired. Please try again.',
 'm_admin_only'=>'Administrator only.','m_inst_ok'=>'Administrator created. Now sign in.',
 'e_name'=>'Name is required.','e_email'=>'Invalid email.','e_passlen'=>'Password must be at least 6 characters.',
 'e_passmatch'=>'Passwords do not match.','e_emaildup'=>'That email is already registered.','e_office'=>'Select a valid office.',
 'e_question'=>'Select a security question.','e_answer'=>'Write the security answer.',
 'e_office_fill'=>'Complete office name and code.','m_reg_ok'=>'Account created as <b>operator</b>. You can sign in now.',
 'm_rec_noacc'=>'No active account with that email.','m_rec_noq'=>'Your account has no recovery question. Contact the administrator.',
 'm_rec_bad'=>'Wrong answer. Try again.','m_rec_okans'=>'Correct answer. Set your new password.',
 'm_rec_done'=>'Password updated. Sign in with your new password.',
 'wt_body'=>'I am honored to address you in order to report… <i>(edit this document and upload the final version from the Cites module)</i>',
 'wt_close'=>'This is all reported for your consideration.','wt_dear'=>'Mr./Ms.:','wt_present'=>'Present.-','wt_ref'=>'Re:',
],
];

function t($k){ global $I18N,$LANG; return $I18N[$LANG][$k] ?? ($I18N['es'][$k] ?? $k); }
function langUrl($l){ $g=$_GET; $g['lang']=$l; return '?'.http_build_query($g); }
function fmt_date($s,$h=false){
  global $LANG;
  if(!$s) return '—';
  $ts=strtotime($s); if(!$ts) return '—';
  $mesES=[1=>'ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];
  $mesEN=[1=>'Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  $o = $LANG==='en'
    ? $mesEN[(int)date('n',$ts)].' '.date('j',$ts).', '.date('Y',$ts)
    : date('j',$ts).' '.$mesES[(int)date('n',$ts)].' '.date('Y',$ts);
  return $h ? $o.' · '.date('H:i',$ts) : $o;
}

/* ---------- HELPERS ---------- */
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function user(){ return $_SESSION['user'] ?? null; }
function redirect($u){ header('Location: '.$u); exit; }
function flash($m=null,$ty='ok'){ if($m===null){ $f=$_SESSION['flash']??null; unset($_SESSION['flash']); return $f; } $_SESSION['flash']=['msg'=>$m,'type'=>$ty]; }
function csrf_token(){ if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16)); return $_SESSION['csrf']; }
function csrf_field(){ return '<input type="hidden" name="csrf" value="'.csrf_token().'">'; }
function csrf_ok($t){ return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'],(string)$t); }
function row($sql,$p=[]){ global $q; $r=$q($sql,$p)->fetch(); return is_array($r)?$r:[]; }
function cnt1($sql,$p=[]){ $r=row($sql,$p); return (int)($r['c'] ?? 0); }

const DER_SELECT = 'd.id AS der_id, d.route_id, d.sender_user_id, d.receiver_user_id, d.instruction,
  d.is_copy, d.sent_at, d.received_at, d.finished_at, d.cancelled,
  r.id, r.tracking_number, r.subject, r.type, r.document_id, r.pages_count, r.annex_count,
  r.attachments, r.priority, r.status, r.archive_folder, r.archive_note, r.created_at';
const STATUS_MAP = ['ENVIADO'=>['st_transit','tr'],'RECIBIDO'=>['st_received','ok'],
                    'ARCHIVADO'=>['st_done','tr'],'CANCELADO'=>['st_cancel','ur']];

function person($id){ static $c=[]; $id=(int)$id;
  if(!isset($c[$id])){ $r=row("SELECT u.*,o.name oname,o.code ocode FROM users u JOIN offices o ON o.id=u.office_id WHERE u.id=?",[$id]);
    $c[$id] = $r ?: ['id'=>$id,'name'=>'—','oname'=>'—','ocode'=>'—','position'=>'','office_id'=>0,'email'=>'']; }
  return $c[$id]; }
function office($id){ static $c=[]; $id=(int)$id;
  if(!isset($c[$id])){ $r=row("SELECT * FROM offices WHERE id=?",[$id]);
    $c[$id] = $r ?: ['id'=>0,'name'=>'—','code'=>'—']; }
  return $c[$id]; }
function next_tracking(){ $y=date('Y');
  $r=row("SELECT tracking_number FROM routes WHERE tracking_number LIKE ? ORDER BY id DESC LIMIT 1",["HR-$y-%"]);
  $n=$r?((int)substr($r['tracking_number'],-4))+1:1; return sprintf('HR-%s-%04d',$y,$n); }
function next_cite_number($officeId,$type){ $y=date('Y');
  $abbr=['informe'=>'INF','nota interna'=>'NI','nota externa'=>'NE'][$type];
  $r=row("SELECT cite_number FROM documents WHERE office_id=? AND type=? ORDER BY id DESC LIMIT 1",[$officeId,$type]);
  $n=1; if($r && preg_match('/Nº\s+(\d+)-/',$r['cite_number'],$m)) $n=(int)$m[1]+1;
  return sprintf('%s-%s-Nº %04d-%d', office($officeId)['code'] ?: 'OF', $abbr, $n, $y); }
function dur_str($a,$b){ $s=max(1,strtotime($b)-strtotime($a)); $d=floor($s/86400); $h=floor($s%86400/3600); $m=floor($s%3600/60);
  return $d>0?"$d d $h h":($h>0?"$h h $m min":"$m min"); }
function dias_habiles($from,$to=null){ if(!$from) return 0;
  $to=$to?new DateTime($to):new DateTime(); $d=new DateTime($from); $d->modify('+1 day'); $n=0;
  while($d<=$to){ if((int)$d->format('N')<6) $n++; $d->modify('+1 day'); } return $n; }
function dias_restantes($prio,$recv){ return ($prio==='urgente'?2:5) - dias_habiles($recv); }
function sla_html($rest){
  if($rest<0)  return '<span class="sla ur">'.e(sprintf(t('sla_over'),-$rest)).'</span>';
  if($rest===0)return '<span class="sla ur">'.e(t('sla_today')).'</span>';
  if($rest<=2) return '<span class="sla wn">'.e(sprintf(t('sla_left'),$rest)).'</span>';
  return '<span class="sla ok">'.e(sprintf(t('sla_left'),$rest)).'</span>';
}
function bandeja_counts($uid){ return [
  'entrantes' => cnt1("SELECT COUNT(*) c FROM route_derivations d JOIN routes r ON r.id=d.route_id WHERE d.receiver_user_id=? AND d.is_copy=0 AND d.cancelled=0 AND d.received_at IS NULL AND r.status='ENVIADO'",[$uid]),
  'pendientes'=> cnt1("SELECT COUNT(*) c FROM route_derivations d JOIN routes r ON r.id=d.route_id WHERE d.receiver_user_id=? AND d.is_copy=0 AND d.cancelled=0 AND d.received_at IS NOT NULL AND d.finished_at IS NULL AND r.status='RECIBIDO'",[$uid]),
  'copias'    => cnt1("SELECT COUNT(*) c FROM route_derivations d JOIN routes r ON r.id=d.route_id WHERE d.receiver_user_id=? AND d.is_copy=1 AND d.cancelled=0 AND d.received_at IS NULL AND r.status IN ('ENVIADO','RECIBIDO')",[$uid])]; }
function mi_deriv($uid,$routeId){ return row("SELECT * FROM route_derivations
  WHERE route_id=? AND receiver_user_id=? AND is_copy=0 AND cancelled=0 ORDER BY id DESC LIMIT 1",[$routeId,$uid]); }
function proveidos(){ return [t('pv1'),t('pv2'),t('pv3'),t('pv4')]; }
function security_questions(){ return ['q1','q2','q3','q4','q5']; }
function dest_select($name,$uid,$required=false,$multiple=false,$includeSelf=false){
  global $q;
  $rows=$q("SELECT o.name oname,u.id uid,u.name uname,u.position upos FROM offices o JOIN users u ON u.office_id=o.id WHERE u.active=1 ORDER BY o.id,u.name")->fetchAll();
  $por=[]; foreach($rows as $r){
    if($r['uid']==$uid && !$includeSelf) continue;
    $por[$r['oname']][]=$r;
  }
  $req=$required?' required':''; $nm=$multiple?$name.'[]':$name; $ml=$multiple?' multiple size="4"':'';
  $h="<select class=\"in\" name=\"$nm\"$ml$req><option value=\"\">".e(t('dest_ph'))."</option>";
  foreach($por as $of=>$us){ $h.="<optgroup label=\"".e($of)."\">";
    foreach($us as $u){
      $lbl=$u['uname'].($u['uid']==$uid?' ('.t('you').')':'').($multiple?'':' — '.$u['upos']);
      $h.="<option value=\"".(int)$u['uid']."\">".e($lbl)."</option>";
    }
    $h.="</optgroup>"; }
  return $h.'</select>'; }
function hay_destinatarios($uid){ return cnt1("SELECT COUNT(*) c FROM users WHERE active=1"); }

function ico($n){
  $o='<svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">';
  switch($n){
    case 'mail':     return $o.'<rect x="3" y="5" width="18" height="14" rx="3"/><path d="M3.5 7.5 12 13l8.5-5.5"/></svg>';
    case 'user':     return $o.'<circle cx="12" cy="8" r="3.4"/><path d="M5 20c1.4-3.2 4-4.6 7-4.6s5.6 1.4 7 4.6"/></svg>';
    case 'building': return $o.'<rect x="4.5" y="3.5" width="15" height="17" rx="2.5"/><path d="M9 7.5h1.6M13.4 7.5H15M9 11h1.6M13.4 11H15M9 14.5h1.6M13.4 14.5H15M10 20.5v-3h4v3"/></svg>';
    case 'brief':    return $o.'<rect x="3.5" y="7.5" width="17" height="12" rx="2.5"/><path d="M9 7.5V6a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v1.5"/><path d="M3.5 12.5h17"/></svg>';
    case 'arrow':    return $o.'<path d="M5 12h13"/><path d="M13 6.5 19 12l-6 5.5"/></svg>';
    case 'help':     return $o.'<circle cx="12" cy="12" r="8.5"/><path d="M9.6 9.4a2.5 2.5 0 1 1 3.6 2.3c-.8.4-1.2.9-1.2 1.8"/><circle cx="12" cy="16.8" r=".9" fill="currentColor" stroke="none"/></svg>';
    case 'shield':   return $o.'<path d="M12 3.5 5 6v5.5c0 4.2 2.9 7.3 7 9 4.1-1.7 7-4.8 7-9V6Z"/><path d="M9.2 12.2l2 2 3.6-4"/></svg>';
  }
  return $o.'</svg>';
}
function pwToggle($target){
  return '<button type="button" class="pw-toggle" data-toggle="'.e($target).'" aria-label="Show / hide password" tabindex="-1">
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
    <path class="lk-shackle" d="M8.5 10.5V7.5a3.5 3.5 0 0 1 7 0v3"/>
    <rect class="lk-body" x="5" y="10.5" width="14" height="9.5" rx="2.6"/>
    <line class="lk-key" x1="12" y1="14.2" x2="12" y2="16.2"/>
  </svg></button>';
}
function fieldIc($icon,$inputHtml){
  return '<div class="field"><span class="lic">'.ico($icon).'</span>'.$inputHtml.'</div>';
}

/* ---------- RUTA ---------- */
 $p   = $_GET['p'] ?? 'dashboard';
 $id  = (int)($_REQUEST['id'] ?? 0);
 $uid = (int)(user()['id'] ?? 0);

/* ---------- PLANTILLA WORD ---------- */
if($p==='plantilla' && $uid){
  $d=row("SELECT d.*,o.name oname,u.name uname,u.position upos FROM documents d JOIN offices o ON o.id=d.office_id
          JOIN users u ON u.id=d.user_id WHERE d.id=? AND d.user_id=?",[$id,$uid]);
  if(empty($d)) redirect('?p=cites');
  while(ob_get_level()) ob_end_clean();
  $abbr=['informe'=>t('informe'),'nota interna'=>t('nota_int'),'nota externa'=>t('nota_ext')][$d['type']] ?? '';
  header('Content-Type: application/msword');
  header('Content-Disposition: attachment; filename="CITE_'.preg_replace('/[^\w]/','_',$d['cite_number']).'.doc"');
  echo '<html><head><meta charset="utf-8"></head><body style="font-family:\'Times New Roman\',serif">';
  echo '<div style="text-align:center"><b style="font-size:15pt">INSTITUCIÓN</b><br><span style="font-size:11pt">'.e($d['oname']).'</span></div>';
  echo '<hr style="border:none;border-top:3px double #000;margin:10pt 0">';
  echo '<p style="font-family:\'Courier New\',monospace;font-size:10.5pt"><b>CITE: '.e($d['cite_number']).'</b></p>';
  echo '<p style="font-size:11pt">'.e($d['oname']).', '.fmt_date(date('Y-m-d')).'</p>';
  echo '<p style="font-size:11pt"><b>'.e(mb_strtoupper($abbr)).'</b></p>';
  echo '<p style="font-size:11pt"><b>'.t('wt_dear').'</b><br>_________________________________<br><i>'.t('wt_present').'</i></p>';
  echo '<p style="font-size:11pt"><b>'.t('wt_ref').' '.e($d['subject']).'</b></p>';
  echo '<p style="font-size:11pt;text-align:justify;line-height:1.6">'.t('wt_body').'</p>';
  echo '<p style="font-size:11pt">'.t('wt_close').'</p><br><br>';
  echo '<p style="text-align:center;font-size:11pt">_______________________________<br>'.e($d['uname']).'<br>'.e($d['upos']).'<br>'.e($d['oname']).'</p>';
  echo '</body></html>'; exit;
}

/* =========================== POST =========================== */
if($_SERVER['REQUEST_METHOD']==='POST'){
  $post = function($k,$d=''){ return trim($_POST[$k] ?? $d); };

  if($p==='login'){
    if(!csrf_ok($_POST['csrf']??'')){ flash(t('m_csrf'),'err'); redirect('?p=login'); }
    $u=row("SELECT * FROM users WHERE email=? AND active=1",[$post('email')]);
    $pass=$_POST['password']??'';
    $ok = !empty($u) && password_verify($pass,(string)($u['password'] ?? ''));
    if(!$ok && !empty($u) && ($u['password'] ?? '')===$pass){
      $ok=true;
      $q("UPDATE users SET password=? WHERE id=?",[password_hash($pass,PASSWORD_DEFAULT),(int)$u['id']]);
    }
    if($ok){
      session_regenerate_id(true);
      $_SESSION['user']=['id'=>(int)$u['id'],'name'=>$u['name'],'office_id'=>(int)$u['office_id'],'position'=>$u['position'],'role'=>$u['role']];
      redirect('?p=dashboard');
    }
    flash(t('m_login_fail'),'err'); redirect('?p=login');
  }

  if($p==='instalar'){
    if(!csrf_ok($_POST['csrf']??'')){ flash(t('m_csrf'),'err'); redirect('?p=instalar'); }
    if(cnt1("SELECT COUNT(*) c FROM users")>0) redirect('?p=login');
    $name=$post('name'); $email=$post('email'); $pass=$_POST['password']??'';
    $pos=$post('position','Administrador'); $ofn=$post('of_name'); $ofc=strtoupper($post('of_code'));
    $err=[];
    if($name==='') $err[]=t('e_name');
    if(!filter_var($email,FILTER_VALIDATE_EMAIL)) $err[]=t('e_email');
    if(strlen($pass)<6) $err[]=t('e_passlen');
    if($ofn===''||$ofc==='') $err[]=t('e_office_fill');
    if($err){ flash(implode(' ',$err),'err'); redirect('?p=instalar'); }
    $q("INSERT INTO offices (name,code) VALUES (?,?)",[$ofn,$ofc]);
    $q("INSERT INTO users (name,email,password,role,office_id,position,active) VALUES (?,?,?,'admin',?,?,1)",
      [$name,$email,password_hash($pass,PASSWORD_DEFAULT),(int)$pdo->lastInsertId(),$pos]);
    flash(t('m_inst_ok')); redirect('?p=login');
  }

  if($p==='registro'){
    if(!csrf_ok($_POST['csrf']??'')){ flash(t('m_csrf'),'err'); redirect('?p=registro'); }
    if(cnt1("SELECT COUNT(*) c FROM users")===0) redirect('?p=instalar');
    $name=$post('name'); $email=$post('email'); $pass=$_POST['password']??''; $pass2=$_POST['password2']??'';
    $pos=$post('position'); $oid=(int)($post('office_id')?:0);
    $sq=$post('security_question'); $sa=$post('security_answer');
    $err=[];
    if($name==='') $err[]=t('e_name');
    if(!filter_var($email,FILTER_VALIDATE_EMAIL)) $err[]=t('e_email');
    if(!empty(row("SELECT id FROM users WHERE email=?",[$email]))) $err[]=t('e_emaildup');
    if(strlen($pass)<6) $err[]=t('e_passlen');
    if($pass!==$pass2) $err[]=t('e_passmatch');
    if(!$oid || empty(row("SELECT id FROM offices WHERE id=?",[$oid]))) $err[]=t('e_office');
    if(!in_array($sq, security_questions(), true)) $err[]=t('e_question');
    if(mb_strlen($sa)<2) $err[]=t('e_answer');
    if($err){ flash(implode(' ',$err),'err'); redirect('?p=registro'); }
    $q("INSERT INTO users (name,email,password,role,office_id,position,active,reset_question,reset_answer)
        VALUES (?,?,?,'operador',?,?,1,?,?)",
      [$name,$email,password_hash($pass,PASSWORD_DEFAULT),$oid,$pos,$sq,password_hash(mb_strtolower($sa),PASSWORD_DEFAULT)]);
    flash(t('m_reg_ok')); redirect('?p=login');
  }

  if($p==='recuperar'){
    if(!csrf_ok($_POST['csrf']??'')){ flash(t('m_csrf'),'err'); redirect('?p=recuperar'); }
    $op=$post('op');
    if($op==='buscar'){
      $u=row("SELECT * FROM users WHERE email=? AND active=1",[$post('email')]);
      if(empty($u)){ flash(t('m_rec_noacc'),'err'); redirect('?p=recuperar'); }
      if(empty($u['reset_question'])){ flash(t('m_rec_noq'),'err'); redirect('?p=login'); }
      $_SESSION['rec_uid']=(int)$u['id']; unset($_SESSION['rec_ok']);
      redirect('?p=recuperar');
    }
    if($op==='responder'){
      $rid=(int)($_SESSION['rec_uid']??0); if(!$rid) redirect('?p=recuperar');
      $u=row("SELECT * FROM users WHERE id=? AND active=1",[$rid]);
      if(empty($u)){ unset($_SESSION['rec_uid']); redirect('?p=recuperar'); }
      $stored=(string)($u['reset_answer'] ?? '');
      $given=mb_strtolower($post('answer'));
      $valid = $stored!=='' && (password_verify($given,$stored) || $stored===$given);
      if($valid){ $_SESSION['rec_ok']=true; flash(t('m_rec_okans')); }
      else flash(t('m_rec_bad'),'err');
      redirect('?p=recuperar');
    }
    if($op==='cambiar'){
      $rid=(int)($_SESSION['rec_uid']??0);
      if(!$rid || empty($_SESSION['rec_ok'])) redirect('?p=recuperar');
      $p1=$_POST['password']??''; $p2=$_POST['password2']??'';
      if(strlen($p1)<6){ flash(t('e_passlen'),'err'); redirect('?p=recuperar'); }
      if($p1!==$p2){ flash(t('e_passmatch'),'err'); redirect('?p=recuperar'); }
      $q("UPDATE users SET password=? WHERE id=?",[password_hash($p1,PASSWORD_DEFAULT),$rid]);
      unset($_SESSION['rec_uid'],$_SESSION['rec_ok']);
      flash(t('m_rec_done')); redirect('?p=login');
    }
    redirect('?p=recuperar');
  }

  if(!user()) redirect('?p=login');
  if(!csrf_ok($_POST['csrf']??'')){ flash(t('m_csrf'),'err'); redirect('?p=dashboard'); }
  $me=user(); $uid=(int)$me['id'];

  if($p==='recibir'){
    $d=row("SELECT d.*,r.status rs FROM route_derivations d JOIN routes r ON r.id=d.route_id WHERE d.id=? AND d.receiver_user_id=?",[$id,$uid]);
    if(!empty($d) && empty($d['received_at']) && empty($d['cancelled']) && $d['rs']==='ENVIADO'){
      $q("UPDATE route_derivations SET received_at=? WHERE id=?",[now(),$id]);
      $q("UPDATE routes SET status='RECIBIDO', current_office_id=? WHERE id=?",[$d['receiver_office_id'],$d['route_id']]);
      flash(t('m_received'));
    } else flash(t('m_no_recv'),'err');
    redirect('?p=pendientes');
  }

  if($p==='nueva'){
    $tipo=($_POST['tipo']??'')==='con_cite'?'con_cite':'sin_cite';
    $docId=$tipo==='con_cite'?(int)($_POST['document_id']??0):null;
    $sub=$post('subject'); $to=(int)($post('destinatario')?:0); $ins=$post('instruction');
    $prio=($_POST['priority']??'')==='urgente'?'urgente':'normal';
    $pags=max(1,(int)($post('pages_count','1')?:1)); $anex=max(0,(int)($post('annex_count','0')?:0));
    $copias=array_values(array_filter(array_map('intval',(array)($_POST['copias']??[])),fn($c)=>$c&&$c!==$to&&$c!==$uid));
    if(!$to && !hay_destinatarios($uid)){
      flash($me['role']==='admin'?t('no_users_a'):t('no_users_u'),'err');
      redirect($me['role']==='admin'?'?p=usuarios':'?p=dashboard');
    }
    if($docId && empty(row("SELECT id FROM documents WHERE id=? AND user_id=?",[$docId,$uid]))) $docId=null;
    $toU=$to?person($to):[];
    if($sub===''||empty($toU)||$ins===''||($tipo==='con_cite'&&!$docId)){
      flash($tipo==='con_cite'?t('m_need_cite'):t('m_need'),'err'); redirect('?p=nueva');
    }
    $adj=[];
    if(!empty($_FILES['adjuntos']) && is_array($_FILES['adjuntos']['name']) && !empty($_FILES['adjuntos']['name'][0])){
      foreach($_FILES['adjuntos']['name'] as $i=>$n){
        if(($_FILES['adjuntos']['error'][$i]??1)!==UPLOAD_ERR_OK) continue;
        if($_FILES['adjuntos']['size'][$i]>5*1024*1024){ flash(t('m_cite_5m'),'err'); redirect('?p=nueva'); }
        $safe=time()."_{$i}_".preg_replace('/[^A-Za-z0-9._-]/','_',basename($n));
        if(move_uploaded_file($_FILES['adjuntos']['tmp_name'][$i],__DIR__.'/uploads/'.$safe)) $adj[]=['name'=>basename($n),'file'=>$safe];
      }
    }
    $tk=next_tracking();
    $ts=now();
    $q("INSERT INTO routes (tracking_number,creator_user_id,current_office_id,type,document_id,subject,pages_count,annex_count,attachments,priority,status,created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,'ENVIADO',?)",
      [$tk,$uid,$toU['office_id'],$tipo,$docId,$sub,$pags,$anex,json_encode($adj,JSON_UNESCAPED_UNICODE),$prio,$ts]);
    $rid=(int)$pdo->lastInsertId();
    $q("INSERT INTO route_derivations (route_id,sender_user_id,receiver_user_id,sender_office_id,receiver_office_id,instruction,sent_at) VALUES (?,?,?,?,?,?,?)",
      [$rid,$uid,$to,$me['office_id'],$toU['office_id'],$ins,$ts]);
    foreach($copias as $c){ $cu=person($c);
      $q("INSERT INTO route_derivations (route_id,sender_user_id,receiver_user_id,sender_office_id,receiver_office_id,instruction,is_copy,sent_at)
          VALUES (?,?,?,?,?,'Copia informativa.',1,?)",[$rid,$uid,$c,$me['office_id'],$cu['office_id'],$ts]); }
    flash(sprintf(t('m_hr_sent'),e($tk),e($toU['name'])));
    redirect('?p=ver&id='.$rid);
  }

  if($p==='derivar'){
    $d=mi_deriv($uid,$id); $r=row("SELECT * FROM routes WHERE id=?",[$id]);
    $to=(int)($post('destinatario')?:0); $ins=$post('instruction');
    $att=(int)($post('attach_document_id')?:0)?:null;
    $copias=array_values(array_filter(array_map('intval',(array)($_POST['copias']??[])),fn($c)=>$c&&$c!==$to&&$c!==$uid));
    if(!empty($d) && !empty($r) && $r['status']==='RECIBIDO' && $to && $ins!=='' && $to!==$uid){
      $toU=person($to); $ts=now();
      $q("UPDATE route_derivations SET finished_at=? WHERE id=?",[$ts,$d['id']]);
      $q("INSERT INTO route_derivations (route_id,sender_user_id,receiver_user_id,sender_office_id,receiver_office_id,instruction,attach_document_id,sent_at)
          VALUES (?,?,?,?,?,?,?,?)",[$id,$uid,$to,$me['office_id'],$toU['office_id'],$ins,$att,$ts]);
      foreach($copias as $c){ $cu=person($c);
        $q("INSERT INTO route_derivations (route_id,sender_user_id,receiver_user_id,sender_office_id,receiver_office_id,instruction,is_copy,sent_at)
            VALUES (?,?,?,?,?,'Copia informativa.',1,?)",[$id,$uid,$c,$me['office_id'],$cu['office_id'],$ts]); }
      $q("UPDATE routes SET status='ENVIADO', current_office_id=? WHERE id=?",[$toU['office_id'],$id]);
      flash(sprintf(t('m_derived'),e($toU['name'])));
    } else flash(t('m_derive_fail'),'err');
    redirect('?p=enviados');
  }

  if($p==='archivar'){
    $d=mi_deriv($uid,$id); $r=row("SELECT * FROM routes WHERE id=?",[$id]);
    $fold=$post('folder'); $note=$post('note');
    if(!empty($d) && !empty($r) && $r['status']==='RECIBIDO' && $note!=='' && $d['finished_at']===null){
      $q("UPDATE route_derivations SET finished_at=? WHERE id=?",[now(),$d['id']]);
      $q("UPDATE routes SET status='ARCHIVADO', archive_folder=?, archive_note=? WHERE id=?",[$fold,$note,$id]);
      flash(sprintf(t('m_archived'),e($fold))); redirect('?p=archivo');
    }
    flash(t('m_arch_fail'),'err'); redirect('?p=pendientes');
  }

  if($p==='desarchivar'){
    $r=row("SELECT * FROM routes WHERE id=?",[$id]); $d=mi_deriv($uid,$id);
    if(!empty($r) && $r['status']==='ARCHIVADO' && !empty($d)){
      $q("UPDATE route_derivations SET finished_at=NULL WHERE id=?",[$d['id']]);
      $q("UPDATE routes SET status='RECIBIDO' WHERE id=?",[$id]);
      flash(t('m_unarch')); redirect('?p=pendientes');
    }
    redirect('?p=archivo');
  }

  if($p==='cancelar'){
    $d=row("SELECT d.*,r.status rs FROM route_derivations d JOIN routes r ON r.id=d.route_id WHERE d.id=? AND d.sender_user_id=? AND d.is_copy=0",[$id,$uid]);
    if(!empty($d) && empty($d['received_at']) && empty($d['cancelled']) && $d['rs']==='ENVIADO'){
      $q("UPDATE route_derivations SET cancelled=1 WHERE id=?",[$id]);
      $prev=row("SELECT * FROM route_derivations WHERE route_id=? AND is_copy=0 AND cancelled=0 AND id<? ORDER BY id DESC LIMIT 1",[$d['route_id'],$id]);
      if(!empty($prev)){ $q("UPDATE route_derivations SET finished_at=NULL WHERE id=?",[$prev['id']]);
                 $q("UPDATE routes SET status='RECIBIDO', current_office_id=? WHERE id=?",[$prev['receiver_office_id'],$d['route_id']]); }
      else $q("UPDATE routes SET status='CANCELADO' WHERE id=?",[$d['route_id']]);
      flash(t('m_cancel_ok'));
    } else flash(t('m_cancel_fail'),'err');
    redirect('?p=enviados');
  }

  if($p==='group_form'){
    $ids=array_values(array_filter(array_map('intval',(array)($_POST['ids']??[]))));
    if(count($ids)<2){ flash(t('m_grp_need'),'err'); redirect('?p=pendientes'); }
    $_SESSION['group_ids']=$ids; redirect('?p=group');
  }
  if($p==='group'){
    $ids=$_SESSION['group_ids']??[]; $parent=(int)($post('parent')?:0);
    if($parent && count($ids)>=2 && in_array($parent,$ids,true)){
      $n=0;
      foreach($ids as $cid){ if($cid===$parent) continue;
        if(!empty(row("SELECT id FROM route_groups WHERE child_route_id=?",[$cid]))) continue;
        $q("INSERT INTO route_groups (parent_route_id,child_route_id,created_by,created_at) VALUES (?,?,?,?)",[$parent,$cid,$uid,now()]); $n++; }
      unset($_SESSION['group_ids']);
      flash($n?sprintf(t('m_grp_ok'),$n):t('m_grp_zero'),$n?'ok':'err');
      redirect('?p=ver&id='.$parent);
    }
    flash(t('m_grp_exp'),'err'); redirect('?p=pendientes');
  }

  if($p==='cites'){
    $op=$_POST['op']??'';
    if($op==='crear'){
      $type=in_array($_POST['type']??'',['informe','nota interna','nota externa'],true)?$_POST['type']:'informe';
      $sub=$post('subject');
      if($sub!==''){ $cite=next_cite_number($me['office_id'],$type);
        $q("INSERT INTO documents (user_id,office_id,type,cite_number,subject,created_at) VALUES (?,?,?,?,?,?)",
          [$uid,$me['office_id'],$type,$cite,$sub,now()]);
        flash(sprintf(t('m_cite_ok'),e($cite)));
      } else flash(t('m_cite_need'),'err');
    }
    if($op==='subir'){
      $did=(int)($post('document_id')?:0); $d=row("SELECT * FROM documents WHERE id=? AND user_id=?",[$did,$uid]);
      $f=$_FILES['archivo']??null;
      if(!empty($d) && $f && ($f['error']??1)===UPLOAD_ERR_OK){
        if($f['size']>5*1024*1024){ flash(t('m_cite_5m'),'err'); redirect('?p=cites'); }
        $safe=time().'_'.preg_replace('/[^A-Za-z0-9._-]/','_',basename($f['name']));
        if(move_uploaded_file($f['tmp_name'],__DIR__.'/uploads/cites_'.$safe)){
          $q("UPDATE documents SET file_path=?, status='final' WHERE id=?",['uploads/cites_'.$safe,$did]);
          flash(sprintf(t('m_cite_up'),e($d['cite_number']))); }
      } else flash(t('m_cite_bad'),'err');
    }
    redirect('?p=cites');
  }

  if($p==='usuarios'){
    if($me['role']!=='admin'){ flash(t('m_admin_only'),'err'); redirect('?p=dashboard'); }
    $op=$_POST['op']??'';
    if($op==='crear'||$op==='editar'){
      $uidE=(int)($post('id')?:0); $name=$post('name'); $email=$post('email');
      $role=in_array($_POST['role']??'',['admin','jefe','operador'],true)?$_POST['role']:'operador';
      $oid=(int)($post('office_id')?:0); $pos=$post('position'); $pass=$_POST['password']??''; $act=isset($_POST['active'])?1:0;
      $err=[];
      if($name==='') $err[]=t('e_name');
      if(!filter_var($email,FILTER_VALIDATE_EMAIL)) $err[]=t('e_email');
      if(!$oid) $err[]=t('e_office');
      if($op==='crear' && strlen($pass)<6) $err[]=t('e_passlen');
      if(!empty(row("SELECT id FROM users WHERE email=? AND id<>?",[$email,$uidE]))) $err[]=t('e_emaildup');
      if(!$err){
        if($op==='crear'){
          $q("INSERT INTO users (name,email,password,role,office_id,position,active) VALUES (?,?,?,?,?,?,?)",
            [$name,$email,password_hash($pass,PASSWORD_DEFAULT),$role,$oid,$pos,$act]);
          flash(sprintf(t('m_user_ok'),e($name),e($role)));
        }else{
          if($pass!=='') $q("UPDATE users SET name=?,email=?,password=?,role=?,office_id=?,position=?,active=? WHERE id=?",
            [$name,$email,password_hash($pass,PASSWORD_DEFAULT),$role,$oid,$pos,$act,$uidE]);
          else $q("UPDATE users SET name=?,email=?,role=?,office_id=?,position=?,active=? WHERE id=?",[$name,$email,$role,$oid,$pos,$act,$uidE]);
          flash(t('m_user_upd'));
        }
      } else flash(implode(' ',$err),'err');
      redirect('?p=usuarios');
    }
    if($op==='toggle'){ $tgid=(int)($post('id')?:0); $u=row("SELECT * FROM users WHERE id=?",[$tgid]);
      if(!empty($u)){ if($tgid===$uid) flash(t('m_user_selfoff'),'err');
        else{ $q("UPDATE users SET active=? WHERE id=?",[$u['active']?0:1,$tgid]); flash(t('m_user_state')); } }
      redirect('?p=usuarios'); }
    if($op==='eliminar'){ $tgid=(int)($post('id')?:0);
      if($tgid===$uid){ flash(t('m_user_selfdel'),'err'); }
      else{
        $usado = cnt1("SELECT COUNT(*) c FROM routes WHERE creator_user_id=?",[$tgid])
               + cnt1("SELECT COUNT(*) c FROM route_derivations WHERE sender_user_id=? OR receiver_user_id=?",[$tgid,$tgid])
               + cnt1("SELECT COUNT(*) c FROM documents WHERE user_id=?",[$tgid]);
        if($usado>0){ flash(t('m_user_has'),'err'); }
        else{ $q("DELETE FROM users WHERE id=?",[$tgid]); flash(t('m_user_del')); }
      }
      redirect('?p=usuarios'); }
    redirect('?p=usuarios');
  }

  if($p==='oficinas'){
    if($me['role']!=='admin'){ flash(t('m_admin_only'),'err'); redirect('?p=dashboard'); }
    $op=$_POST['op']??'';
    if($op==='crear'||$op==='editar'){
      $oid=(int)($post('id')?:0); $name=$post('name'); $code=strtoupper($post('code'));
      if($name!=='' && $code!==''){
        try{
          if($op==='crear'){ $q("INSERT INTO offices (name,code) VALUES (?,?)",[$name,$code]); flash(t('m_off_ok')); }
          else{ $q("UPDATE offices SET name=?,code=? WHERE id=?",[$name,$code,$oid]); flash(t('m_off_upd')); }
        }catch(Exception $ex){ flash(t('m_off_dup'),'err'); }
      } else flash(t('m_off_need'),'err');
      redirect('?p=oficinas');
    }
    if($op==='eliminar'){ $oid=(int)($post('id')?:0);
      $usado = cnt1("SELECT COUNT(*) c FROM users WHERE office_id=?",[$oid])
             + cnt1("SELECT COUNT(*) c FROM routes WHERE current_office_id=?",[$oid])
             + cnt1("SELECT COUNT(*) c FROM documents WHERE office_id=?",[$oid]);
      if($usado>0){ flash(t('m_off_has'),'err'); }
      else{ $q("DELETE FROM offices WHERE id=?",[$oid]); flash(t('m_off_del')); }
      redirect('?p=oficinas'); }
    redirect('?p=oficinas');
  }
}

if($p==='logout'){ session_destroy(); redirect('?p=login'); }

 $hayUsuarios = cnt1("SELECT COUNT(*) c FROM users");
 $publico = ['login','instalar','registro','recuperar'];
if($hayUsuarios===0 && $p!=='instalar') redirect('?p=instalar');
if(!user() && !in_array($p,$publico)) redirect('?p=login');
if(user() && in_array($p,$publico)) redirect('?p=dashboard');
if(in_array($p,['usuarios','oficinas']) && (user()['role']??'')!=='admin'){ flash(t('m_admin_only'),'err'); redirect('?p=dashboard'); }
if(!in_array($p,['dashboard','entrantes','pendientes','enviados','archivo','nueva','ver','derivar','archivar','cites','buscar','usuarios','oficinas','group','group_form','recibir','cancelar','desarchivar','login','instalar','registro','recuperar','plantilla'])) $p='dashboard';
 $me=user(); $uid=(int)($me['id'] ?? 0);

/* =========================== CONTENIDO =========================== */
ob_start();
 $lsw = '<div class="lang-sw"><a href="'.e(langUrl('es')).'"'.($LANG==='es'?' class="on"':'').'>ES</a><a href="'.e(langUrl('en')).'"'.($LANG==='en'?' class="on"':'').'>EN</a></div>';

/* ---------- INSTALADOR ---------- */
if($p==='instalar'): ?>
<form method="post" action="?p=instalar" class="acard">
  <?php echo csrf_field(); ?>
  <div class="acard-brand"><span class="sq">T</span><div><b>TRÁMITE</b><small><?php echo e(t('tag')); ?></small></div></div>
  <h2 class="at"><?php echo t('inst_t'); ?></h2>
  <p class="asub"><?php echo t('inst_s'); ?></p>
  <label class="lb"><?php echo t('inst_office'); ?></label>
  <div class="arow">
    <?php echo fieldIc('building','<input class="in has-ic" name="of_name" placeholder="Secretaría General" required>'); ?>
    <input class="in" name="of_code" placeholder="CÓDIGO" maxlength="10" style="max-width:120px;text-transform:uppercase" required>
  </div>
  <label class="lb" style="margin-top:14px"><?php echo t('inst_admin'); ?></label>
  <?php echo fieldIc('user','<input class="in has-ic" name="name" placeholder="'.t('name_full').'" required>'); ?>
  <?php echo fieldIc('mail','<input class="in has-ic" type="email" name="email" placeholder="correo@institucion.gob" style="margin-top:9px" required>'); ?>
  <?php echo fieldIc('brief','<input class="in has-ic" name="position" placeholder="'.t('position').'" style="margin-top:9px">'); ?>
  <div class="field" style="margin-top:9px">
    <input class="in has-tg" id="pw-inst" type="password" name="password" placeholder="<?php echo t('password'); ?>" required>
    <?php echo pwToggle('pw-inst'); ?></div>
  <button class="btn pri abig" type="submit"><span><?php echo t('inst_btn'); ?></span><?php echo ico('arrow'); ?></button>
</form>
<?php endif;

/* ---------- LOGIN ---------- */
if($p==='login'): ?>
<form method="post" action="?p=login" class="acard">
  <?php echo csrf_field(); ?>
  <div class="acard-brand"><span class="sq">T</span><div><b>TRÁMITE</b><small><?php echo e(t('tag')); ?></small></div></div>
  <h2 class="at"><?php echo t('login_title'); ?></h2>
  <p class="asub"><?php echo t('login_sub'); ?></p>
  <label class="lb"><?php echo t('email'); ?></label>
  <?php echo fieldIc('mail','<input class="in has-ic" type="email" name="email" autocomplete="username" required autofocus>'); ?>
  <label class="lb" style="margin-top:14px"><?php echo t('password'); ?></label>
  <div class="field">
    <input class="in has-tg" id="pw-login" type="password" name="password" autocomplete="current-password" required>
    <?php echo pwToggle('pw-login'); ?></div>
  <div class="alinks"><a href="?p=recuperar"><?php echo t('forgot'); ?></a></div>
  <button class="btn pri abig" type="submit"><span><?php echo t('login_btn'); ?></span><?php echo ico('arrow'); ?></button>
</form>
<?php endif;

/* ---------- REGISTRO ---------- */
if($p==='registro'):
  $ofis=$q("SELECT * FROM offices ORDER BY name")->fetchAll();
  if(!$ofis): ?>
  <div class="acard">
    <div class="acard-brand"><span class="sq">T</span><div><b>TRÁMITE</b><small><?php echo e(t('tag')); ?></small></div></div>
    <h2 class="at"><?php echo t('reg_title'); ?></h2>
    <p class="asub"><?php echo t('reg_no_offices'); ?></p>
    <a class="btn gho abig" href="?p=login"><?php echo t('reg_back'); ?></a>
  </div>
  <?php else: ?>
  <form method="post" action="?p=registro" class="acard">
    <?php echo csrf_field(); ?>
    <div class="acard-brand"><span class="sq">T</span><div><b>TRÁMITE</b><small><?php echo e(t('tag')); ?></small></div></div>
    <h2 class="at"><?php echo t('reg_title'); ?></h2>
    <p class="asub"><?php echo t('reg_sub'); ?></p>
    <label class="lb"><?php echo t('name_full'); ?></label>
    <?php echo fieldIc('user','<input class="in has-ic" name="name" required>'); ?>
    <label class="lb" style="margin-top:12px"><?php echo t('email_login'); ?></label>
    <?php echo fieldIc('mail','<input class="in has-ic" type="email" name="email" autocomplete="username" required>'); ?>
    <div class="arow" style="margin-top:12px">
      <div style="flex:1;min-width:0"><label class="lb"><?php echo t('office'); ?></label>
        <div class="field"><span class="lic"><?php echo ico('building'); ?></span>
        <select class="in has-ic" name="office_id" required><option value=""><?php echo t('dest_ph'); ?></option>
        <?php foreach($ofis as $o): ?><option value="<?php echo (int)$o['id']; ?>"><?php echo e($o['name'].' ('.$o['code'].')'); ?></option><?php endforeach; ?>
        </select></div></div>
      <div style="flex:1;min-width:0"><label class="lb"><?php echo t('position'); ?></label>
        <?php echo fieldIc('brief','<input class="in has-ic" name="position">'); ?></div>
    </div>
    <div class="arow" style="margin-top:12px">
      <div style="flex:1;min-width:0"><label class="lb"><?php echo t('password'); ?></label>
        <div class="field">
          <input class="in has-tg" id="rp1" type="password" name="password" minlength="6" autocomplete="new-password" required>
          <?php echo pwToggle('rp1'); ?></div></div>
      <div style="flex:1;min-width:0"><label class="lb"><?php echo t('confirm'); ?></label>
        <div class="field">
          <input class="in has-tg" id="rp2" type="password" name="password2" minlength="6" autocomplete="new-password" required>
          <?php echo pwToggle('rp2'); ?></div></div>
    </div>
    <label class="lb" style="margin-top:12px"><?php echo t('reg_question'); ?></label>
    <div class="field"><span class="lic"><?php echo ico('shield'); ?></span>
    <select class="in has-ic" name="security_question" required>
      <option value=""><?php echo t('dest_ph'); ?></option>
      <?php foreach(security_questions() as $qk): ?><option value="<?php echo $qk; ?>"><?php echo e(t($qk)); ?></option><?php endforeach; ?>
    </select></div>
    <label class="lb" style="margin-top:10px"><?php echo t('reg_answer'); ?></label>
    <?php echo fieldIc('help','<input class="in has-ic" name="security_answer" required placeholder="'.t('answer_ph').'">'); ?>
    <button class="btn pri abig" type="submit"><span><?php echo t('reg_btn'); ?></span><?php echo ico('arrow'); ?></button>
  </form>
  <?php endif;
endif;

/* ---------- RECUPERAR ---------- */
if($p==='recuperar'):
  $recUid = (int)($_SESSION['rec_uid'] ?? 0);
  $recOk  = !empty($_SESSION['rec_ok']);
  if($recOk): $ru=row("SELECT name FROM users WHERE id=?",[$recUid]); ?>
  <form method="post" action="?p=recuperar" class="acard">
    <?php echo csrf_field(); ?>
    <div class="acard-brand"><span class="sq">T</span><div><b>TRÁMITE</b><small><?php echo e(t('tag')); ?></small></div></div>
    <h2 class="at"><?php echo t('rec3_t'); ?></h2>
    <p class="asub"><?php echo t('rec3_s'); ?><?php echo !empty($ru['name'])?' — <b>'.e($ru['name']).'</b>':''; ?></p>
    <input type="hidden" name="op" value="cambiar">
    <label class="lb"><?php echo t('new_pass'); ?></label>
    <div class="field">
      <input class="in has-tg" id="np1" type="password" name="password" minlength="6" required>
      <?php echo pwToggle('np1'); ?></div>
    <label class="lb" style="margin-top:12px"><?php echo t('confirm'); ?></label>
    <div class="field">
      <input class="in has-tg" id="np2" type="password" name="password2" minlength="6" required>
      <?php echo pwToggle('np2'); ?></div>
    <button class="btn pri abig" type="submit"><span><?php echo t('rec3_btn'); ?></span><?php echo ico('arrow'); ?></button>
  </form>
  <?php elseif($recUid): $ru=row("SELECT reset_question,name FROM users WHERE id=?",[$recUid]);
    if(empty($ru)){ unset($_SESSION['rec_uid']); redirect('?p=recuperar'); } ?>
  <form method="post" action="?p=recuperar" class="acard">
    <?php echo csrf_field(); ?>
    <div class="acard-brand"><span class="sq">T</span><div><b>TRÁMITE</b><small><?php echo e(t('tag')); ?></small></div></div>
    <h2 class="at"><?php echo t('rec2_t'); ?></h2>
    <p class="asub"><?php echo t('rec2_s'); ?> <b><?php echo e($ru['name']); ?></b></p>
    <input type="hidden" name="op" value="responder">
    <label class="lb"><?php echo e(t($ru['reset_question'] ?? 'q1')); ?></label>
    <?php echo fieldIc('help','<input class="in has-ic" name="answer" required autofocus placeholder="'.t('answer_ph').'">'); ?>
    <button class="btn pri abig" type="submit"><span><?php echo t('rec2_btn'); ?></span><?php echo ico('arrow'); ?></button>
    <div class="alinks"><a href="?p=login"><?php echo t('rec_cancel'); ?></a></div>
  </form>
  <?php else: ?>
  <form method="post" action="?p=recuperar" class="acard">
    <?php echo csrf_field(); ?>
    <div class="acard-brand"><span class="sq">T</span><div><b>TRÁMITE</b><small><?php echo e(t('tag')); ?></small></div></div>
    <h2 class="at"><?php echo t('rec1_t'); ?></h2>
    <p class="asub"><?php echo t('rec1_s'); ?></p>
    <input type="hidden" name="op" value="buscar">
    <label class="lb"><?php echo t('email'); ?></label>
    <?php echo fieldIc('mail','<input class="in has-ic" type="email" name="email" required autofocus>'); ?>
    <button class="btn pri abig" type="submit"><span><?php echo t('rec1_btn'); ?></span><?php echo ico('arrow'); ?></button>
    <div class="alinks"><a href="?p=login"><?php echo t('rec_cancel'); ?></a></div>
  </form>
  <?php endif;
endif;

/* ---------- DASHBOARD ---------- */
if($p==='dashboard'):
  $entrantes=$q("SELECT ".DER_SELECT." FROM route_derivations d JOIN routes r ON r.id=d.route_id
    WHERE d.receiver_user_id=? AND d.is_copy=0 AND d.cancelled=0 AND d.received_at IS NULL AND r.status='ENVIADO'
    ORDER BY r.priority='urgente' DESC, d.sent_at ASC LIMIT 6",[$uid])->fetchAll();
  $pend=$q("SELECT ".DER_SELECT." FROM route_derivations d JOIN routes r ON r.id=d.route_id
    WHERE d.receiver_user_id=? AND d.is_copy=0 AND d.cancelled=0 AND d.received_at IS NOT NULL AND d.finished_at IS NULL AND r.status='RECIBIDO'
    ORDER BY r.priority='urgente' DESC, d.received_at ASC",[$uid])->fetchAll();
  $urg=count(array_filter($pend,fn($x)=>$x['priority']==='urgente'));
  $rec=$q("SELECT d.sent_at,d.sender_user_id,d.receiver_user_id,r.subject,su.name sname,ru.name rname
    FROM route_derivations d JOIN routes r ON r.id=d.route_id JOIN users su ON su.id=d.sender_user_id JOIN users ru ON ru.id=d.receiver_user_id
    WHERE d.is_copy=0 AND (d.sender_user_id=? OR d.receiver_user_id=?) ORDER BY d.sent_at DESC LIMIT 7",[$uid,$uid])->fetchAll();
  $movs=$q("SELECT DATE(sent_at) dia,COUNT(*) n FROM route_derivations WHERE sent_at>=DATE('now','localtime','-6 day') GROUP BY DATE(sent_at)")->fetchAll();
  $map=array_column($movs,'n','dia');
  $letras = $LANG==='en' ? ['','M','T','W','T','F','S','S'] : ['','L','M','M','J','V','S','D'];
  $sem=[];
  for($i=6;$i>=0;$i--){ $ts=strtotime("-$i day"); $sem[]=['l'=>$letras[(int)date('N',$ts)],'n'=>(int)($map[date('Y-m-d',$ts)]??0)]; }
  $mx=max(1,max(array_column($sem,'n')));
?>
<div class="pg-h">
  <div><h1><?php echo t('hello'); ?> <?php echo e(explode(' ',$me['name'])[0] ?? ''); ?></h1>
  <p><?php echo e(office($me['office_id'] ?? 0)['name']); ?> ·
     <?php echo count($entrantes)?'<b>'.count($entrantes).' '.t('by_receive').'</b>':'0 '.t('by_receive'); ?> ·
     <?php echo $urg; ?> URG</p></div>
  <a class="btn pri" href="?p=nueva"><i data-lucide="plus"></i><?php echo t('nav_new'); ?></a>
</div>
<div class="dash-grid">
  <div class="stack">
    <div class="card">
      <div class="card-h"><i data-lucide="inbox" class="hico"></i><?php echo t('dash_action'); ?> <span class="sp"></span><span class="chip gy"><?php echo t('dash_entry'); ?></span></div>
      <?php if(!$entrantes && !$pend): ?><div class="empty"><b><?php echo t('dash_ok_t'); ?></b><?php echo t('dash_ok_p'); ?></div><?php endif; ?>
      <?php foreach($entrantes as $d): $cr=person($d['sender_user_id']); ?>
      <div class="row">
        <div class="mid"><div class="ref"><?php echo e($d['subject']); ?></div>
          <div class="sub"><span class="chip tk"><?php echo e($d['tracking_number']); ?></span> <?php echo t('sent_by'); ?> <b><?php echo e($cr['name']); ?></b> (<?php echo e($cr['ocode']); ?>)</div></div>
        <div class="rit">
          <?php if($d['priority']==='urgente'): ?><span class="chip ur"><span class="dot"></span>URG</span><?php endif; ?>
          <form method="post" action="?p=recibir&id=<?php echo (int)$d['der_id']; ?>" style="display:inline"><?php echo csrf_field(); ?>
            <button class="abtn ok" title="<?php echo t('receive'); ?>"><i data-lucide="check"></i></button></form>
          <a class="abtn" href="?p=ver&id=<?php echo (int)$d['id']; ?>"><i data-lucide="eye"></i></a>
        </div>
      </div>
      <?php endforeach; ?>
      <?php foreach(array_slice($pend,0,4) as $d): ?>
      <div class="row">
        <div class="mid"><div class="ref"><?php echo e($d['subject']); ?></div>
          <div class="sub"><span class="chip tk"><?php echo e($d['tracking_number']); ?></span> <?php echo t('in_respons'); ?></div></div>
        <div class="rit">
          <?php echo sla_html(dias_restantes($d['priority'],$d['received_at'])); ?>
          <a class="abtn" href="?p=derivar&id=<?php echo (int)$d['id']; ?>"><i data-lucide="corner-down-right"></i></a>
          <a class="abtn" href="?p=ver&id=<?php echo (int)$d['id']; ?>"><i data-lucide="git-branch"></i></a>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="card">
      <div class="card-h"><i data-lucide="activity" class="hico"></i><?php echo t('act_recent'); ?></div>
      <?php if(!$rec): ?><div class="empty"><b><?php echo t('act_empty_t'); ?></b><?php echo t('act_empty_p'); ?></div><?php endif; ?>
      <?php foreach($rec as $d): $mio=$d['receiver_user_id']==$uid; ?>
      <div class="act-row"><span class="adot"></span>
        <div><?php echo $mio?'<b>'.e($d['sname']).'</b> '.t('act_you_sent'):t('act_sent_to').' <b>'.e($d['rname']).'</b>'; ?> — <?php echo e($d['subject']); ?>
        <time><?php echo e(fmt_date($d['sent_at'],true)); ?></time></div></div>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="stack">
    <div class="card">
      <div class="stats">
        <div class="stat"><span class="sic"><i data-lucide="inbox"></i></span><div><b><?php echo count($entrantes); ?></b><small><?php echo e(t('by_receive')); ?></small></div></div>
        <div class="stat"><span class="sic"><i data-lucide="hourglass"></i></span><div><b><?php echo count($pend); ?></b><small><?php echo e(t('nav_pending')); ?></small></div></div>
        <div class="stat"><span class="sic"><i data-lucide="alarm-clock"></i></span><div><b class="ur"><?php echo $urg; ?></b><small>URG</small></div></div>
        <div class="stat"><span class="sic"><i data-lucide="file-plus-2"></i></span><div><b><?php echo cnt1("SELECT COUNT(*) c FROM routes WHERE creator_user_id=?",[$uid]); ?></b><small><?php echo e(t('created_by_you')); ?></small></div></div>
        <div class="stat wide"><span class="sic"><i data-lucide="stamp"></i></span><div><b><?php echo cnt1("SELECT COUNT(*) c FROM documents WHERE user_id=?",[$uid]); ?></b><small><?php echo e(t('cites_issued')); ?></small></div></div>
      </div>
    </div>
    <div class="card">
      <div class="card-h"><i data-lucide="trending-up" class="hico"></i><?php echo t('week_moves'); ?></div>
      <div class="chart"><?php foreach($sem as $i=>$d): ?>
        <div class="cw"><div class="bar <?php echo $i===6?'':'dim'; ?>" style="height:<?php echo max(4,round($d['n']/$mx*100)); ?>%"></div><span class="cl"><?php echo $d['l']; ?></span></div>
      <?php endforeach; ?></div>
    </div>
  </div>
</div>
<?php endif;

/* ---------- ENTRANTES ---------- */
if($p==='entrantes'):
  $lista=$q("SELECT ".DER_SELECT." FROM route_derivations d JOIN routes r ON r.id=d.route_id
    WHERE d.receiver_user_id=? AND d.is_copy=0 AND d.cancelled=0 AND d.received_at IS NULL AND r.status='ENVIADO'
    ORDER BY r.priority='urgente' DESC, d.sent_at ASC",[$uid])->fetchAll();
  $copias=$q("SELECT ".DER_SELECT." FROM route_derivations d JOIN routes r ON r.id=d.route_id
    WHERE d.receiver_user_id=? AND d.is_copy=1 AND d.cancelled=0 AND d.received_at IS NULL AND r.status IN ('ENVIADO','RECIBIDO')
    ORDER BY d.sent_at DESC",[$uid])->fetchAll();
?>
<div class="pg-h"><div><h1><?php echo t('inbox_h'); ?></h1><p><?php echo t('inbox_p'); ?></p></div></div>
<div class="card">
  <div class="card-h"><i data-lucide="inbox" class="hico"></i><?php echo t('to_receive'); ?> <span class="sp"></span><span class="chip gy"><?php echo count($lista); ?></span></div>
  <?php if(!$lista): ?><div class="empty"><b><?php echo t('inbox_empty_t'); ?></b><?php echo t('inbox_empty_p'); ?></div><?php endif; ?>
  <?php foreach($lista as $d): $de=person($d['sender_user_id']);
    $doc=$d['document_id']?row("SELECT cite_number FROM documents WHERE id=?",[$d['document_id']]):[]; ?>
  <div class="row">
    <div class="mid"><div class="ref"><?php echo e($d['subject']); ?></div>
      <div class="sub"><span class="chip tk"><?php echo e($d['tracking_number']); ?></span>
        <?php echo t('from'); ?> <b><?php echo e($de['name']); ?></b> (<?php echo e($de['ocode']); ?>)
        <?php if(!empty($doc['cite_number'])): ?>· <span class="mono"><?php echo e($doc['cite_number']); ?></span><?php endif; ?>
        · <?php echo (int)$d['pages_count']; ?> <?php echo t('sheets'); ?> · <?php echo e(fmt_date($d['sent_at'],true)); ?></div></div>
    <div class="rit">
      <?php if($d['priority']==='urgente'): ?><span class="chip ur"><span class="dot"></span>URG</span><?php endif; ?>
      <form method="post" action="?p=recibir&id=<?php echo (int)$d['der_id']; ?>" style="display:inline"><?php echo csrf_field(); ?>
        <button class="btn pri sm"><i data-lucide="check"></i><?php echo t('receive'); ?></button></form>
      <a class="abtn" href="?p=ver&id=<?php echo (int)$d['id']; ?>"><i data-lucide="eye"></i></a>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<div class="card" style="margin-top:14px">
  <div class="card-h"><i data-lucide="copy" class="hico"></i><?php echo t('copies'); ?> <span class="sp"></span><span class="chip gy"><?php echo count($copias); ?></span></div>
  <?php if(!$copias): ?><div class="empty"><b><?php echo t('copies_empty_t'); ?></b><?php echo t('copies_empty_p'); ?></div><?php endif; ?>
  <?php foreach($copias as $d): $de=person($d['sender_user_id']); ?>
  <div class="row">
    <div class="mid"><div class="ref"><?php echo e($d['subject']); ?></div>
      <div class="sub"><span class="chip tk"><?php echo e($d['tracking_number']); ?></span> <?php echo t('copy_of'); ?> <b><?php echo e($de['name']); ?></b></div></div>
    <div class="rit"><a class="btn gho sm" href="?p=ver&id=<?php echo (int)$d['id']; ?>"><i data-lucide="eye"></i><?php echo t('view'); ?></a></div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif;

/* ---------- PENDIENTES ---------- */
if($p==='pendientes'):
  $lista=$q("SELECT ".DER_SELECT." FROM route_derivations d JOIN routes r ON r.id=d.route_id
    WHERE d.receiver_user_id=? AND d.is_copy=0 AND d.cancelled=0 AND d.received_at IS NOT NULL AND d.finished_at IS NULL AND r.status='RECIBIDO'
    ORDER BY r.priority='urgente' DESC, d.received_at ASC",[$uid])->fetchAll();
?>
<div class="pg-h">
  <div><h1><?php echo t('pend_h'); ?></h1><p><?php echo t('pend_p'); ?></p></div>
  <button class="btn gho" type="button" id="btnGroup" disabled
    onclick="if(document.querySelectorAll('.ck-group:checked').length<2){alert('<?php echo e(t('m_grp_need')); ?>');return false;}document.getElementById('fGroup').submit();">
    <i data-lucide="layers"></i><?php echo t('group_btn'); ?> (<span id="nSel">0</span>)
  </button>
</div>
<form method="post" action="?p=group_form" id="fGroup">
<div class="card">
  <?php if(!$lista): ?><div class="empty"><b><?php echo t('pend_empty_t'); ?></b><?php echo t('pend_empty_p'); ?></div><?php endif; ?>
  <?php foreach($lista as $d): $de=person($d['sender_user_id']);
    $g=row("SELECT r.tracking_number ptk FROM route_groups g JOIN routes r ON r.id=g.parent_route_id WHERE g.child_route_id=?",[$d['id']]); ?>
  <div class="row">
    <input type="checkbox" class="ck ck-group" name="ids[]" value="<?php echo (int)$d['id']; ?>">
    <div class="mid">
      <div class="ref"><?php echo e($d['subject']); ?>
        <?php if($d['priority']==='urgente'): ?><span class="chip ur" style="vertical-align:2px"><span class="dot"></span>URG</span><?php endif; ?>
        <?php if(!empty($g['ptk'])): ?><span class="chip gy" style="vertical-align:2px"><?php echo t('grouped_to'); ?> <?php echo e($g['ptk']); ?></span><?php endif; ?></div>
      <div class="sub"><span class="chip tk"><?php echo e($d['tracking_number']); ?></span> <?php echo t('from'); ?> <b><?php echo e($de['name']); ?></b> · <?php echo t('received_on'); ?> <?php echo e(fmt_date($d['received_at'])); ?></div>
    </div>
    <div class="rit">
      <?php echo sla_html(dias_restantes($d['priority'],$d['received_at'])); ?>
      <a class="abtn" href="?p=derivar&id=<?php echo (int)$d['id']; ?>" title="<?php echo t('derive_btn'); ?>"><i data-lucide="corner-down-right"></i></a>
      <a class="abtn" href="?p=archivar&id=<?php echo (int)$d['id']; ?>" title="<?php echo t('arch_f_t'); ?>"><i data-lucide="archive"></i></a>
      <a class="abtn" href="?p=ver&id=<?php echo (int)$d['id']; ?>" title="<?php echo t('trace_lbl'); ?>"><i data-lucide="git-branch"></i></a>
    </div>
  </div>
  <?php endforeach; ?>
</div>
</form>
<?php endif;

/* ---------- FORM AGRUPAR ---------- */
if($p==='group' && $_SERVER['REQUEST_METHOD']!=='POST'):
  $ids=$_SESSION['group_ids']??[]; $rutas=[];
  if(count($ids)>=2){
    $in=implode(',',array_map('intval',$ids));
    $rutas=$q("SELECT DISTINCT r.* FROM routes r JOIN route_derivations d ON d.route_id=r.id
      WHERE r.id IN ($in) AND d.receiver_user_id=? AND d.is_copy=0 AND d.cancelled=0
      AND d.received_at IS NOT NULL AND d.finished_at IS NULL AND r.status='RECIBIDO'
      AND NOT EXISTS (SELECT 1 FROM route_groups g WHERE g.child_route_id=r.id)",[$uid])->fetchAll();
  }
  if(count($rutas)<2){ unset($_SESSION['group_ids']); flash(t('m_grp_none'),'err'); redirect('?p=pendientes'); }
?>
<form method="post" action="?p=group" class="acard" style="max-width:620px">
  <?php echo csrf_field(); ?>
  <h2 class="at"><?php echo t('group_h'); ?></h2>
  <p class="asub"><?php echo t('group_s'); ?></p>
  <?php foreach($rutas as $i=>$r): ?>
  <label class="pick">
    <input type="radio" name="parent" value="<?php echo (int)$r['id']; ?>" <?php echo $i===0?'checked':''; ?> required>
    <span><b><?php echo e($r['subject']); ?></b><br><span class="mono" style="font-size:11px;color:var(--tx2)"><?php echo e($r['tracking_number']); ?></span></span>
  </label>
  <?php endforeach; ?>
  <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:14px">
    <a class="btn gho" href="?p=pendientes"><?php echo t('cancel'); ?></a>
    <button class="btn pri" type="submit"><i data-lucide="layers"></i><?php echo t('group_create'); ?></button>
  </div>
</form>
<?php endif;

/* ---------- ENVIADOS ---------- */
if($p==='enviados'):
  $lista=$q("SELECT ".DER_SELECT." FROM route_derivations d JOIN routes r ON r.id=d.route_id
    WHERE d.sender_user_id=? AND d.is_copy=0 ORDER BY d.sent_at DESC",[$uid])->fetchAll();
?>
<div class="pg-h"><div><h1><?php echo t('sent_h'); ?></h1><p><?php echo t('sent_p'); ?></p></div></div>
<div class="card">
  <?php if(!$lista): ?><div class="empty"><b><?php echo t('sent_empty_t'); ?></b><?php echo t('sent_empty_p'); ?></div><?php endif; ?>
  <?php foreach($lista as $d): $para=person($d['receiver_user_id']); ?>
  <div class="row">
    <div class="mid">
      <div class="ref" style="<?php echo $d['cancelled']?'text-decoration:line-through;opacity:.6':''; ?>"><?php echo e($d['subject']); ?></div>
      <div class="sub"><span class="chip tk"><?php echo e($d['tracking_number']); ?></span>
        <?php echo t('to_user'); ?> <b><?php echo e($para['name']); ?></b> (<?php echo e($para['ocode']); ?>) · <?php echo e(fmt_date($d['sent_at'],true)); ?></div></div>
    <div class="rit">
      <?php
      if($d['cancelled'])                echo '<span class="sla ur">'.t('st_cancel').'</span>';
      elseif($d['status']==='ARCHIVADO') echo '<span class="sla tr">'.t('st_done').'</span>';
      elseif($d['status']==='CANCELADO') echo '<span class="sla ur">'.t('st_cancel').'</span>';
      elseif(!$d['received_at'])         echo '<span class="sla wn">'.t('st_transit').'</span>';
      else                               echo '<span class="sla ok">'.t('st_received').'</span>'; ?>
      <?php if(!$d['received_at'] && !$d['cancelled'] && $d['status']==='ENVIADO'): ?>
      <form method="post" action="?p=cancelar&id=<?php echo (int)$d['der_id']; ?>" data-confirm="?"><?php echo csrf_field(); ?>
        <button class="abtn" title="<?php echo t('st_cancel'); ?>"><i data-lucide="ban"></i></button></form>
      <?php endif; ?>
      <a class="abtn" href="?p=ver&id=<?php echo (int)$d['id']; ?>"><i data-lucide="git-branch"></i></a>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif;

/* ---------- ARCHIVO ---------- */
if($p==='archivo'):
  $lista=$q("SELECT ".DER_SELECT." FROM route_derivations d JOIN routes r ON r.id=d.route_id
    WHERE d.receiver_user_id=? AND d.is_copy=0 AND r.status='ARCHIVADO' ORDER BY d.finished_at DESC",[$uid])->fetchAll();
?>
<div class="pg-h"><div><h1><?php echo t('arch_h'); ?></h1><p><?php echo t('arch_p'); ?></p></div></div>
<div class="card">
  <?php if(!$lista): ?><div class="empty"><b><?php echo t('arch_empty_t'); ?></b><?php echo t('arch_empty_p'); ?></div><?php endif; ?>
  <?php foreach($lista as $d): ?>
  <div class="row">
    <div class="mid"><div class="ref"><?php echo e($d['subject']); ?></div>
      <div class="sub"><span class="chip tk"><?php echo e($d['tracking_number']); ?></span>
        · <?php echo e($d['archive_folder'] ?: '—'); ?><?php if($d['archive_note']): ?> · <em><?php echo e($d['archive_note']); ?></em><?php endif; ?></div></div>
    <div class="rit">
      <span class="sla tr"><?php echo t('archived_on'); ?> <?php echo e(fmt_date($d['finished_at'])); ?></span>
      <form method="post" action="?p=desarchivar&id=<?php echo (int)$d['id']; ?>"><?php echo csrf_field(); ?>
        <button class="btn gho sm"><i data-lucide="archive-restore"></i><?php echo t('unarchive'); ?></button></form>
      <a class="abtn" href="?p=ver&id=<?php echo (int)$d['id']; ?>"><i data-lucide="git-branch"></i></a>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif;

/* ---------- NUEVA ---------- */
if($p==='nueva'):
  $tk=next_tracking();
  $misCites=$q("SELECT * FROM documents WHERE user_id=? AND status='final' ORDER BY created_at DESC",[$uid])->fetchAll();
  $otros=hay_destinatarios($uid);
?>
<div class="pg-h"><div><h1><?php echo t('new_h'); ?></h1><p><?php echo t('new_s'); ?></p></div></div>
<?php if($otros<2): ?>
<div class="toast ur" style="position:static;margin-bottom:14px;animation:none"><?php echo t('only_you'); ?></div>
<?php endif; ?>
<div class="dash-grid" style="grid-template-columns:1fr 260px">
  <form method="post" action="?p=nueva" enctype="multipart/form-data" class="card" style="padding:18px">
    <?php echo csrf_field(); ?>
    <div class="fgrid">
      <div class="full">
        <label class="lb"><?php echo t('type'); ?></label>
        <div class="seg" data-name="tipo">
          <button type="button" data-v="sin_cite" class="on"><?php echo t('no_cite'); ?></button>
          <button type="button" data-v="con_cite"><?php echo t('with_cite'); ?></button>
        </div>
        <input type="hidden" name="tipo" id="tipoInput" value="sin_cite">
      </div>
      <div class="full" id="wrapCite" style="display:none">
        <label class="lb"><?php echo t('cite_doc'); ?></label>
        <select class="in" name="document_id"><option value=""><?php echo t('dest_ph'); ?></option>
          <?php foreach($misCites as $c): ?><option value="<?php echo (int)$c['id']; ?>"><?php echo e($c['cite_number'].' · '.$c['subject']); ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="full">
        <label class="lb"><?php echo t('subject'); ?></label>
        <input class="in" name="subject" placeholder="<?php echo t('subject_ph'); ?>" required>
      </div>
      <div><label class="lb"><?php echo t('pages'); ?></label><input class="in" type="number" name="pages_count" min="1" value="1"></div>
      <div><label class="lb"><?php echo t('annexes'); ?></label><input class="in" type="number" name="annex_count" min="0" value="0"></div>
      <div>
        <label class="lb"><?php echo t('priority'); ?></label>
        <div class="seg" data-name="prio">
          <button type="button" data-v="normal" class="on"><?php echo t('prio_n'); ?></button>
          <button type="button" data-v="urgente"><?php echo t('prio_u'); ?></button>
        </div>
        <input type="hidden" name="priority" id="prioInput" value="normal">
      </div>
      <div>
        <label class="lb"><?php echo t('dest'); ?></label>
        <?php echo dest_select('destinatario',$uid,true,false,true); ?>
      </div>
      <div class="full">
        <label class="lb"><?php echo t('instruction'); ?></label>
        <textarea class="in" name="instruction" id="provTA" required placeholder="<?php echo t('instr_ph'); ?>"></textarea>
        <div style="margin-top:7px"><?php foreach(proveidos() as $pv): ?>
          <button type="button" class="qchip" data-prov="<?php echo e($pv); ?>" data-target="provTA"><?php echo e($pv); ?></button>
        <?php endforeach; ?></div>
      </div>
      <div class="full">
        <label class="lb"><?php echo t('copies_opt'); ?></label>
        <?php echo dest_select('copias',$uid,false,true); ?>
      </div>
      <div class="full">
        <label class="lb"><?php echo t('attach_files'); ?></label>
        <label class="filebox"><i data-lucide="upload-cloud"></i><?php echo t('attach_hint'); ?>
          <input type="file" name="adjuntos[]" id="adjInput" multiple hidden></label>
        <div id="adjList" style="margin-top:7px;font-size:12px;color:var(--tx2)"></div>
      </div>
    </div>
    <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:16px">
      <a class="btn gho" href="?p=dashboard"><?php echo t('cancel'); ?></a>
      <button class="btn pri" type="submit"><i data-lucide="send"></i><?php echo t('save_send'); ?></button>
    </div>
  </form>
  <div class="card" style="padding:16px">
    <span class="lb"><?php echo t('receipt'); ?></span>
    <div style="font:700 13px 'Sora',sans-serif">TRÁMITE</div>
    <div style="border-top:2.5px solid var(--inv);border-bottom:1px solid var(--inv);height:5px;margin:10px 0 12px"></div>
    <div style="font:700 19px 'JetBrains Mono',monospace;letter-spacing:.03em"><?php echo e($tk); ?></div>
    <p style="font-size:12px;color:var(--tx2);margin-top:10px"><?php echo t('receipt_note'); ?></p>
  </div>
</div>
<?php endif;

/* ---------- FORM DERIVAR ---------- */
if($p==='derivar' && $_SERVER['REQUEST_METHOD']!=='POST'):
  $d=mi_deriv($uid,$id); $r=row("SELECT * FROM routes WHERE id=?",[$id]);
  if(empty($d) || empty($r) || $r['status']!=='RECIBIDO' || $d['received_at']===null || $d['finished_at']!==null){ redirect('?p=pendientes'); }
  $misCites=$q("SELECT * FROM documents WHERE user_id=? ORDER BY created_at DESC",[$uid])->fetchAll();
?>
<form method="post" action="?p=derivar&id=<?php echo $id; ?>" class="acard" style="max-width:620px">
  <?php echo csrf_field(); ?>
  <h2 class="at"><?php echo t('derive_h'); ?></h2>
  <p class="asub"><span class="chip tk"><?php echo e($r['tracking_number']); ?></span> <?php echo e($r['subject']); ?></p>
  <label class="lb"><?php echo t('derive_to'); ?></label>
  <?php echo dest_select('destinatario',$uid,true); ?>
  <label class="lb" style="margin-top:14px"><?php echo t('new_prov'); ?></label>
  <textarea class="in" name="instruction" id="provTA" required><?php echo e(t('pv1')); ?></textarea>
  <div style="margin-top:7px"><?php foreach(proveidos() as $pv): ?>
    <button type="button" class="qchip" data-prov="<?php echo e($pv); ?>" data-target="provTA"><?php echo e($pv); ?></button>
  <?php endforeach; ?></div>
  <label class="lb" style="margin-top:14px"><?php echo t('attach_own'); ?></label>
  <select class="in" name="attach_document_id"><option value=""><?php echo t('no_doc'); ?></option>
    <?php foreach($misCites as $c): ?><option value="<?php echo (int)$c['id']; ?>"><?php echo e($c['cite_number'].' · '.$c['subject']); ?></option><?php endforeach; ?>
  </select>
  <label class="lb" style="margin-top:14px"><?php echo t('send_copy'); ?></label>
  <?php echo dest_select('copias',$uid,false,true); ?>
  <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:16px">
    <a class="btn gho" href="?p=pendientes"><?php echo t('cancel'); ?></a>
    <button class="btn pri" type="submit"><i data-lucide="corner-down-right"></i><?php echo t('derive_btn'); ?></button>
  </div>
</form>
<?php endif;

/* ---------- FORM ARCHIVAR ---------- */
if($p==='archivar' && $_SERVER['REQUEST_METHOD']!=='POST'):
  $d=mi_deriv($uid,$id); $r=row("SELECT * FROM routes WHERE id=?",[$id]);
  if(empty($d) || empty($r) || $r['status']!=='RECIBIDO' || $d['finished_at']!==null){ redirect('?p=pendientes'); }
?>
<form method="post" action="?p=archivar&id=<?php echo $id; ?>" class="acard" style="max-width:560px">
  <?php echo csrf_field(); ?>
  <h2 class="at"><?php echo t('arch_f_t'); ?></h2>
  <p class="asub"><span class="chip tk"><?php echo e($r['tracking_number']); ?></span> <?php echo e($r['subject']); ?></p>
  <label class="lb"><?php echo t('folder'); ?></label>
  <select class="in" name="folder">
    <option><?php echo e(t('fa1').date('Y')); ?></option>
    <option><?php echo e(t('fa2')); ?></option>
    <option><?php echo e(t('fa3')); ?></option>
    <option><?php echo e(t('fa4')); ?></option>
  </select>
  <label class="lb" style="margin-top:14px"><?php echo t('close_note'); ?></label>
  <textarea class="in" name="note" required placeholder="<?php echo t('close_ph'); ?>"></textarea>
  <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:16px">
    <a class="btn gho" href="?p=pendientes"><?php echo t('cancel'); ?></a>
    <button class="btn pri" type="submit"><i data-lucide="archive"></i><?php echo t('arch_btn'); ?></button>
  </div>
</form>
<?php endif;

/* ---------- VER ---------- */
if($p==='ver'):
  $r=row("SELECT r.*,u.name cname,o.code ocode FROM routes r JOIN users u ON u.id=r.creator_user_id
          JOIN offices o ON o.id=u.office_id WHERE r.id=?",[$id]);
  if(empty($r)){ redirect('?p=dashboard'); }
  $doc=$r['document_id']?row("SELECT * FROM documents WHERE id=?",[$r['document_id']]):[];
  $der=$q("SELECT * FROM route_derivations WHERE route_id=? AND is_copy=0 ORDER BY sent_at ASC,id ASC",[$id])->fetchAll();
  $cps=$q("SELECT * FROM route_derivations WHERE route_id=? AND is_copy=1 ORDER BY sent_at ASC",[$id])->fetchAll();
  $hijas=$q("SELECT r.tracking_number,r.subject,r.id rid FROM route_groups g JOIN routes r ON r.id=g.child_route_id WHERE g.parent_route_id=?",[$id])->fetchAll();
  $padre=row("SELECT r.tracking_number ptk,r.id pid FROM route_groups g JOIN routes r ON r.id=g.parent_route_id WHERE g.child_route_id=?",[$id]);
  $adjs=json_decode((string)($r['attachments'] ?? ''),true) ?: [];
  $est=STATUS_MAP[$r['status']] ?? ['st_transit','tr'];
  $miV=mi_deriv($uid,$id);
  $puedo=!empty($miV) && $r['status']==='RECIBIDO' && $miV['received_at']!==null && $miV['finished_at']===null;
?>
<div class="print-zone">
<div class="pg-h">
  <div>
    <h1 style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      <span class="chip tk" style="font-size:14px;padding:5px 12px"><?php echo e($r['tracking_number']); ?></span>
      <?php if($r['priority']==='urgente'): ?><span class="chip ur"><span class="dot"></span>URG</span><?php endif; ?>
      <span class="sla <?php echo $est[1]; ?>" style="font-size:10px"><?php echo e(t($est[0])); ?></span>
    </h1>
    <p style="font-weight:600;font-size:14.5px;margin-top:7px"><?php echo e($r['subject']); ?></p>
  </div>
  <button class="btn gho" type="button" onclick="window.print()"><i data-lucide="printer"></i><?php echo t('print_btn'); ?></button>
</div>
<div class="card" style="margin-bottom:14px">
  <div class="reg"><b style="font-size:12.5px"><?php echo e($r['cname']); ?> · <?php echo e($r['ocode']); ?></b><span><?php echo t('created_by'); ?></span></div>
  <div class="reg"><b style="font-size:12.5px"><?php echo $r['type']==='con_cite' && !empty($doc['cite_number']) ? e($doc['cite_number']) : t('no_cite'); ?></b><span><?php echo t('document_lbl'); ?></span></div>
  <div class="reg"><b style="font-size:12.5px"><?php echo (int)$r['pages_count']; ?> <?php echo t('sheets'); ?> · <?php echo (int)$r['annex_count']; ?></b><span><?php echo t('docum_lbl'); ?></span></div>
  <div class="reg"><b style="font-size:12.5px"><?php echo e(fmt_date($r['created_at'],true)); ?></b><span><?php echo t('emission'); ?></span></div>
  <?php if($r['archive_folder']): ?>
  <div class="reg"><b style="font-size:12.5px"><?php echo e($r['archive_folder']); ?></b><span><?php echo t('folder_s'); ?></span></div>
  <div class="reg"><b style="font-size:12.5px;font-weight:500"><?php echo e($r['archive_note']); ?></b><span><?php echo t('note_s'); ?></span></div>
  <?php endif; ?>
  <?php if(!empty($padre['ptk'])): ?>
  <div class="reg"><b style="font-size:12.5px"><a href="?p=ver&id=<?php echo (int)$padre['pid']; ?>"><?php echo e($padre['ptk']); ?></a></b><span><?php echo t('expediente'); ?></span></div>
  <?php endif; ?>
  <?php if($hijas): ?>
  <div class="reg"><b style="font-size:12.5px"><?php echo count($hijas); ?> <?php echo t('linked'); ?></b><span><?php echo t('exp_group'); ?></span></div>
  <?php foreach($hijas as $h): ?>
  <div class="reg"><b style="font-size:11.5px;font-weight:500"><a href="?p=ver&id=<?php echo (int)$h['rid']; ?>"><?php echo e($h['tracking_number']); ?></a> — <?php echo e($h['subject']); ?></b><span><?php echo t('daughter'); ?></span></div>
  <?php endforeach; endif; ?>
  <?php foreach($adjs as $a): ?>
  <div class="reg"><b style="font-size:12.5px"><a href="uploads/<?php echo e($a['file']); ?>" target="_blank"><?php echo e($a['name']); ?></a></b><span><?php echo t('attachment'); ?></span></div>
  <?php endforeach; ?>
</div>
<span class="lb"><?php echo t('trace_lbl'); ?></span>
<div class="card" style="padding:16px 18px">
  <div class="tl">
    <?php foreach($der as $d): $de=person($d['sender_user_id']); $pa=person($d['receiver_user_id']); ?>
    <div class="tn <?php echo $d['cancelled']?'cx':'done'; ?>">
      <div class="tdot"></div>
      <div>
        <div class="thead"><b><?php echo e($de['name']); ?> → <?php echo e($pa['name']); ?></b>
          <span class="chip gy"><?php echo e($de['ocode']); ?> → <?php echo e($pa['ocode']); ?></span>
          <time><?php echo e(fmt_date($d['sent_at'],true)); ?></time></div>
        <?php if($d['cancelled']): ?>
          <div class="ttxt" style="font-weight:600"><?php echo t('cancel_note'); ?></div>
        <?php else: ?>
          <div class="tinstr">“<?php echo e($d['instruction']); ?>”</div>
          <?php if($d['attach_document_id']): $ad=row("SELECT cite_number FROM documents WHERE id=?",[$d['attach_document_id']]);
            if(!empty($ad['cite_number'])): ?><div class="tdur"><?php echo t('cite_attached'); ?> <span class="mono"><?php echo e($ad['cite_number']); ?></span></div><?php endif; endif; ?>
          <div class="tdur"><?php if($d['received_at']): ?><?php echo t('recv_at'); ?> <?php echo e(fmt_date($d['received_at'],true)); ?>
            <?php if($d['finished_at']): ?> · <?php echo t('held_for'); ?> <?php echo e(dur_str($d['received_at'],$d['finished_at'])); ?>
            <?php else: ?> · <b><?php echo t('now_held'); ?></b> <?php echo e(dur_str($d['received_at'],date('Y-m-d H:i:s'))); endif; ?>
            <?php else: echo t('await'); endif; ?></div>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
    <?php foreach($cps as $c): $pa=person($c['receiver_user_id']); ?>
    <div class="tn"><div></div>
      <div><div class="thead"><b><?php echo t('copy_to'); ?> <?php echo e($pa['name']); ?></b><time><?php echo e(fmt_date($c['sent_at'],true)); ?></time></div>
      <div class="ttxt"><?php echo $c['received_at']?t('ack_at').' '.e(fmt_date($c['received_at'],true)):t('no_ack'); ?></div></div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php if($puedo): ?>
<div style="display:flex;gap:10px;justify-content:flex-end;margin-top:14px">
  <a class="btn pri" href="?p=derivar&id=<?php echo (int)$id; ?>"><i data-lucide="corner-down-right"></i><?php echo t('derive_btn'); ?></a>
  <a class="btn gho" href="?p=archivar&id=<?php echo (int)$id; ?>"><i data-lucide="archive"></i><?php echo t('arch_f_t'); ?></a>
</div>
<?php endif; ?>
</div>
<?php endif;

/* ---------- CITES ---------- */
if($p==='cites'):
  $lista=$q("SELECT * FROM documents WHERE user_id=? ORDER BY created_at DESC",[$uid])->fetchAll();
?>
<div class="pg-h"><div><h1><?php echo t('cites_h'); ?></h1><p><?php echo t('cites_p'); ?></p></div></div>
<div class="card" style="padding:18px;margin-bottom:14px;max-width:680px">
  <h3 style="font:700 15px 'Sora',sans-serif;margin-bottom:12px"><?php echo t('gen_cite'); ?></h3>
  <form method="post" action="?p=cites" class="fgrid">
    <?php echo csrf_field(); ?><input type="hidden" name="op" value="crear">
    <div class="full">
      <label class="lb"><?php echo t('doc_type'); ?></label>
      <select class="in" name="type">
        <option value="informe"><?php echo t('informe'); ?></option>
        <option value="nota interna"><?php echo t('nota_int'); ?></option>
        <option value="nota externa"><?php echo t('nota_ext'); ?></option>
      </select>
    </div>
    <div class="full">
      <label class="lb"><?php echo t('subject_c'); ?></label>
      <input class="in" name="subject" placeholder="<?php echo t('subject_ph2'); ?>" required>
    </div>
    <div class="full" style="display:flex;justify-content:flex-end">
      <button class="btn pri" type="submit"><i data-lucide="stamp"></i><?php echo t('gen_btn'); ?></button>
    </div>
  </form>
</div>
<div class="card">
  <div class="card-h"><i data-lucide="stamp" class="hico"></i><?php echo t('your_docs'); ?> <span class="sp"></span><span class="chip gy"><?php echo count($lista); ?></span></div>
  <?php if(!$lista): ?><div class="empty"><b><?php echo t('cites_empty_t'); ?></b><?php echo t('cites_empty_p'); ?></div><?php endif; ?>
  <?php foreach($lista as $d): ?>
  <div class="row">
    <div class="mid">
      <div class="ref"><?php echo e($d['subject']); ?></div>
      <div class="sub"><span class="chip tk"><?php echo e($d['cite_number']); ?></span>
        <?php if($d['file_path']): ?> · <a href="<?php echo e($d['file_path']); ?>" target="_blank"><?php echo t('file_loaded'); ?></a>
        <?php else: ?> · <span class="sla wn"><?php echo t('no_file'); ?></span><?php endif; ?>
        · <?php echo e(fmt_date($d['created_at'])); ?></div></div>
    <div class="rit">
      <a class="btn gho sm" href="?p=plantilla&id=<?php echo (int)$d['id']; ?>"><i data-lucide="download"></i><?php echo t('template'); ?></a>
      <?php if(!$d['file_path']): ?>
      <form method="post" action="?p=cites" enctype="multipart/form-data" style="display:inline">
        <?php echo csrf_field(); ?><input type="hidden" name="op" value="subir"><input type="hidden" name="document_id" value="<?php echo (int)$d['id']; ?>">
        <label class="btn gho sm" style="cursor:pointer"><i data-lucide="upload"></i><?php echo t('upload_final'); ?>
          <input type="file" name="archivo" hidden onchange="this.form.submit()" accept=".doc,.docx,.pdf,.odt"></label>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif;

/* ---------- BUSCAR ---------- */
if($p==='buscar'):
  $qq=trim($_GET['q']??''); $res=[];
  if(mb_strlen($qq)>=2){ $like='%'.$qq.'%';
    $res=$q("SELECT DISTINCT r.* FROM routes r LEFT JOIN documents d ON d.id=r.document_id LEFT JOIN users u ON u.id=r.creator_user_id
      WHERE r.tracking_number LIKE ? OR r.subject LIKE ? OR d.cite_number LIKE ? OR u.name LIKE ? ORDER BY r.created_at DESC LIMIT 30",[$like,$like,$like,$like])->fetchAll(); }
?>
<div class="pg-h"><div><h1><?php echo t('search_h'); ?></h1><p><?php echo t('results_for'); ?> “<?php echo e($qq); ?>”</p></div></div>
<div class="card">
  <?php if(!$res): ?><div class="empty"><b><?php echo t('search_empty_t'); ?></b><?php echo t('search_empty_p'); ?></div><?php endif; ?>
  <?php foreach($res as $r): $cr=person($r['creator_user_id']);
    $est=STATUS_MAP[$r['status']] ?? ['st_transit','tr']; ?>
  <div class="row">
    <div class="mid"><div class="ref"><?php echo e($r['subject']); ?></div>
      <div class="sub"><span class="chip tk"><?php echo e($r['tracking_number']); ?></span> <?php echo t('made_by'); ?> <b><?php echo e($cr['name']); ?></b> · <?php echo e(fmt_date($r['created_at'])); ?></div></div>
    <div class="rit"><span class="sla <?php echo $est[1]; ?>"><?php echo e(t($est[0])); ?></span>
      <a class="abtn" href="?p=ver&id=<?php echo (int)$r['id']; ?>"><i data-lucide="eye"></i></a></div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif;

/* ---------- USUARIOS ---------- */
if($p==='usuarios'):
  $edit=!empty($_GET['edit'])?row("SELECT * FROM users WHERE id=?",[(int)$_GET['edit']]):[];
  $lista=$q("SELECT u.*,o.name oname,o.code ocode FROM users u JOIN offices o ON o.id=u.office_id ORDER BY u.active DESC,u.name")->fetchAll();
  $ofis=$q("SELECT * FROM offices ORDER BY name")->fetchAll();
?>
<div class="pg-h"><div><h1><?php echo t('users_h'); ?></h1><p><?php echo t('users_p'); ?></p></div></div>
<div class="card" style="padding:18px;margin-bottom:14px;max-width:760px">
  <h3 style="font:700 15px 'Sora',sans-serif;margin-bottom:12px"><?php echo $edit?t('eu_t'):t('nu_t'); ?></h3>
  <form method="post" action="?p=usuarios" class="fgrid">
    <?php echo csrf_field(); ?><input type="hidden" name="op" value="<?php echo $edit?'editar':'crear'; ?>">
    <?php if($edit): ?><input type="hidden" name="id" value="<?php echo (int)$edit['id']; ?>"><?php endif; ?>
    <div><label class="lb"><?php echo t('name_full'); ?></label><input class="in" name="name" value="<?php echo e($edit['name']??''); ?>" required></div>
    <div><label class="lb"><?php echo t('email_login'); ?></label><input class="in" type="email" name="email" value="<?php echo e($edit['email']??''); ?>" required></div>
    <div><label class="lb"><?php echo t('role'); ?></label>
      <select class="in" name="role">
        <?php foreach(['admin','jefe','operador'] as $rl): ?>
        <option value="<?php echo $rl; ?>" <?php echo ($edit['role']??'')===$rl?'selected':''; ?>><?php echo $rl; ?></option>
        <?php endforeach; ?>
      </select></div>
    <div><label class="lb"><?php echo t('office'); ?></label>
      <select class="in" name="office_id" required><option value=""><?php echo t('dest_ph'); ?></option>
        <?php foreach($ofis as $o): ?>
        <option value="<?php echo (int)$o['id']; ?>" <?php echo ($edit['office_id']??0)==$o['id']?'selected':''; ?>><?php echo e($o['name'].' ('.$o['code'].')'); ?></option>
        <?php endforeach; ?>
      </select></div>
    <div><label class="lb"><?php echo t('position'); ?></label><input class="in" name="position" value="<?php echo e($edit['position']??''); ?>"></div>
    <div><label class="lb"><?php echo t('password'); ?> <?php echo $edit?t('pw_keep'):''; ?></label>
      <input class="in" type="password" name="password" <?php echo $edit?'':'required'; ?> minlength="6"></div>
    <div class="full" style="display:flex;align-items:center;gap:16px;justify-content:flex-end">
      <label style="display:flex;align-items:center;gap:8px;font-size:13px">
        <input type="checkbox" name="active" <?php echo (!$edit||$edit['active'])?'checked':''; ?> style="width:16px;height:16px;accent-color:var(--inv)">
        <?php echo t('active_user'); ?></label>
      <?php if($edit): ?><a class="btn gho" href="?p=usuarios"><?php echo t('cancel'); ?></a><?php endif; ?>
      <button class="btn pri" type="submit"><i data-lucide="user-plus"></i><?php echo $edit?t('save_c'):t('create_u'); ?></button>
    </div>
  </form>
</div>
<div class="card">
  <div class="card-h"><i data-lucide="users-round" class="hico"></i><?php echo t('users_list'); ?> <span class="sp"></span><span class="chip gy"><?php echo count($lista); ?></span></div>
  <?php if(!$lista): ?><div class="empty"><b><?php echo t('users_empty_t'); ?></b><?php echo t('users_empty_p'); ?></div><?php endif; ?>
  <?php foreach($lista as $u): ?>
  <div class="row" style="<?php echo $u['active']?'':'opacity:.55'; ?>">
    <span class="ava"><?php echo e(strtoupper(mb_substr($u['name'],0,1))); ?></span>
    <div class="mid">
      <div class="ref"><?php echo e($u['name']); ?>
        <span class="chip gy" style="vertical-align:2px"><?php echo e($u['role']); ?></span>
        <?php if(!$u['active']): ?><span class="chip ur" style="vertical-align:2px"><?php echo t('inactive'); ?></span><?php endif; ?>
        <?php if($u['id']==$uid): ?><span class="chip tk" style="vertical-align:2px"><?php echo t('you'); ?></span><?php endif; ?></div>
      <div class="sub"><?php echo e($u['email']); ?> · <?php echo e($u['position'] ?: '—'); ?> · <?php echo e($u['oname']); ?> (<?php echo e($u['ocode']); ?>)</div>
    </div>
    <div class="rit">
      <a class="abtn" href="?p=usuarios&edit=<?php echo (int)$u['id']; ?>"><i data-lucide="pen-line"></i></a>
      <?php if($u['id']!=$uid): ?>
      <form method="post" action="?p=usuarios"><?php echo csrf_field(); ?><input type="hidden" name="op" value="toggle"><input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
        <button class="abtn" type="submit"><i data-lucide="<?php echo $u['active']?'user-x':'user-check'; ?>"></i></button></form>
      <form method="post" action="?p=usuarios" data-confirm="?"><?php echo csrf_field(); ?><input type="hidden" name="op" value="eliminar"><input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
        <button class="abtn" type="submit"><i data-lucide="trash-2"></i></button></form>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif;

/* ---------- OFICINAS ---------- */
if($p==='oficinas'):
  $edit=!empty($_GET['edit'])?row("SELECT * FROM offices WHERE id=?",[(int)$_GET['edit']]):[];
  $lista=$q("SELECT o.*,(SELECT COUNT(*) FROM users u WHERE u.office_id=o.id) nusers FROM offices o ORDER BY o.name")->fetchAll();
?>
<div class="pg-h"><div><h1><?php echo t('off_h'); ?></h1><p><?php echo t('off_p'); ?></p></div></div>
<div class="card" style="padding:18px;margin-bottom:14px;max-width:640px">
  <h3 style="font:700 15px 'Sora',sans-serif;margin-bottom:12px"><?php echo $edit?t('eo_t'):t('no_t'); ?></h3>
  <form method="post" action="?p=oficinas" style="display:flex;gap:10px;flex-wrap:wrap">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="op" value="<?php echo $edit?'editar':'crear'; ?>">
    <?php if($edit): ?><input type="hidden" name="id" value="<?php echo (int)$edit['id']; ?>"><?php endif; ?>
    <input class="in" name="name" placeholder="<?php echo t('off_name'); ?>" style="flex:1;min-width:200px" value="<?php echo e($edit['name']??''); ?>" required>
    <input class="in" name="code" placeholder="<?php echo t('code'); ?>" maxlength="10" style="max-width:140px;text-transform:uppercase" value="<?php echo e($edit['code']??''); ?>" required>
    <button class="btn pri" type="submit"><?php echo $edit?t('save'):t('add'); ?></button>
    <?php if($edit): ?><a class="btn gho" href="?p=oficinas"><?php echo t('cancel'); ?></a><?php endif; ?>
  </form>
</div>
<div class="card">
  <div class="card-h"><i data-lucide="building-2" class="hico"></i><?php echo t('off_list'); ?> <span class="sp"></span><span class="chip gy"><?php echo count($lista); ?></span></div>
  <?php if(!$lista): ?><div class="empty"><b><?php echo t('off_empty_t'); ?></b><?php echo t('off_empty_p'); ?></div><?php endif; ?>
  <?php foreach($lista as $o): ?>
  <div class="row">
    <div class="mid"><div class="ref"><?php echo e($o['name']); ?></div>
      <div class="sub"><span class="chip tk"><?php echo e($o['code']); ?></span> <?php echo (int)$o['nusers']; ?> <?php echo t('n_users'); ?></div></div>
    <div class="rit">
      <a class="abtn" href="?p=oficinas&edit=<?php echo (int)$o['id']; ?>"><i data-lucide="pen-line"></i></a>
      <form method="post" action="?p=oficinas" data-confirm="?"><?php echo csrf_field(); ?><input type="hidden" name="op" value="eliminar"><input type="hidden" name="id" value="<?php echo (int)$o['id']; ?>">
        <button class="abtn" type="submit"><i data-lucide="trash-2"></i></button></form>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif;

 $content = ob_get_clean();

/* =========================== PLANTILLA =========================== */
 $tema = $_COOKIE['tema'] ?? ((date('H')>=19||date('H')<7)?'dark':'light');
 $f = flash();
 $independiente = in_array($p,['login','instalar','registro','recuperar']);

 $CSS = <<<'CSS'
:root{--bg:#F6F6F4;--sf:#FFFFFF;--el:#F1F1EE;--bd:#E7E7E3;--bd2:#CFCFC9;
--tx:#111111;--tx2:#5B5B57;--tx3:#9E9E98;
--inv:rgba(208,63,63,.9);--on-inv:#FFFFFF;--inv-line:rgba(208,63,63,.35);
--r:14px;--rr:11px;--ease:cubic-bezier(.22,.9,.3,1)}
html[data-theme="dark"]{--bg:#0C0C0C;--sf:#151515;--el:#1E1E1E;--bd:#272727;--bd2:#404040;
--tx:#F4F4F2;--tx2:#ABABA6;--tx3:#6F6F6A;
--inv:#F4F4F2;--on-inv:#0C0C0C;--inv-line:rgba(0,0,0,.3)}
*{box-sizing:border-box;margin:0;padding:0}
body{color:var(--tx);font:14px/1.5 'Plus Jakarta Sans',system-ui,sans-serif;-webkit-font-smoothing:antialiased;
background:radial-gradient(900px 420px at 88% -8%, rgba(208,63,63,.07), transparent 60%),
radial-gradient(700px 380px at -6% 108%, rgba(208,63,63,.05), transparent 55%), var(--bg);
background-attachment:fixed}
html[data-theme="dark"] body{background:var(--bg)}
a{color:inherit;text-decoration:none}button{font:inherit;cursor:pointer;border:none;background:none;color:inherit}
input,select,textarea{font:inherit;color:var(--tx)}::selection{background:var(--inv);color:var(--on-inv)}
::-webkit-scrollbar{width:8px;height:8px}::-webkit-scrollbar-thumb{background:var(--bd2);border-radius:8px}
::-webkit-scrollbar-track{background:transparent}
.mono{font-family:'JetBrains Mono',monospace}
svg.ic{width:16px;height:16px;flex:none;vertical-align:-3px;transition:transform .18s var(--ease)}
svg:not(.lucide):not(.ic){width:16px;height:16px;flex:none}
svg.lucide{width:15px;height:15px;flex:none;vertical-align:-3px}
.btn svg.lucide,.abtn svg.lucide{width:14px;height:14px}
.btn svg.ic{width:15px;height:15px}
@keyframes fadeUp{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
@keyframes popIn{from{opacity:0;transform:translateY(12px) scale(.985)}to{opacity:1;transform:none}}
@keyframes growBar{from{transform:scaleY(0)}}
@keyframes toastIn{from{transform:translateY(60px);opacity:0}}
@media (prefers-reduced-motion:reduce){*{animation:none!important;transition:none!important}}
.layout{display:flex;min-height:100vh}
.sidebar{width:216px;flex:none;background:var(--sf);border-right:1.5px solid var(--bd);display:flex;flex-direction:column;position:sticky;top:0;height:100vh;z-index:40}
.brand{display:flex;gap:9px;align-items:center;padding:16px 14px 12px}
.brand .sq{width:34px;height:34px;border-radius:11px;color:var(--on-inv);display:flex;align-items:center;justify-content:center;font:700 15px 'Sora',sans-serif;background:linear-gradient(135deg,var(--inv),color-mix(in srgb,var(--inv) 78%,var(--bg)))}
.brand b{font:700 14.5px 'Sora',sans-serif;display:block;line-height:1.1;letter-spacing:-.01em}
.brand small{font:600 8px 'JetBrains Mono',monospace;letter-spacing:.14em;text-transform:uppercase;color:var(--tx2)}
.nav{flex:1;overflow-y:auto;padding:0 9px 9px}
.nav-t{font:600 8px 'JetBrains Mono',monospace;letter-spacing:.18em;text-transform:uppercase;color:var(--tx3);padding:12px 8px 5px}
.nav a{display:flex;align-items:center;gap:9px;padding:7.5px 10px;border-radius:var(--rr);color:var(--tx2);font-weight:500;font-size:13.5px;margin-bottom:1px;position:relative;transition:background .15s var(--ease),color .15s var(--ease),transform .15s var(--ease)}
.nav a:hover{color:var(--tx);background:var(--el);transform:translateX(2px)}
.nav a.on{background:var(--inv);color:var(--on-inv);font-weight:600;transform:none}
.nav a.on::before{content:"";position:absolute;left:-9px;top:50%;transform:translateY(-50%);width:3px;height:16px;border-radius:3px;background:var(--inv)}
.nav .cnt{margin-left:auto;font:700 10px 'JetBrains Mono',monospace;background:var(--on-inv);color:var(--inv);min-width:17px;height:17px;border-radius:6px;display:flex;align-items:center;justify-content:center;padding:0 4px}
.side-u{border-top:1.5px solid var(--bd);padding:9px}
.su-card{display:flex;gap:9px;align-items:center;padding:4px 6px}
.su-card b{display:block;font-size:12.5px;line-height:1.25}
.logout{display:flex;gap:8px;align-items:center;padding:7px 10px;color:var(--tx2);border-radius:var(--rr);font-size:12.5px;margin-top:4px;transition:background .15s,color .15s}
.logout:hover{color:var(--tx);background:var(--el)}
.ava{width:30px;height:30px;border-radius:50%;color:var(--on-inv);display:flex;align-items:center;justify-content:center;font:700 12px 'Sora',sans-serif;flex:none;background:linear-gradient(135deg,var(--inv),color-mix(in srgb,var(--inv) 78%,var(--bg)))}
.backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:39}
.main{flex:1;min-width:0;display:flex;flex-direction:column}
.topbar{position:sticky;top:0;z-index:30;display:flex;align-items:center;gap:10px;padding:10px 20px;background:var(--bg);border-bottom:1.5px solid var(--bd)}
.burger{display:none;width:34px;height:34px;border:1.5px solid var(--bd);border-radius:var(--rr);align-items:center;justify-content:center;background:var(--sf)}
.gsearch{flex:1;max-width:500px;display:flex;gap:8px;align-items:center;background:var(--sf);border:1.5px solid var(--bd);border-radius:var(--rr);padding:0 12px;height:36px;transition:border-color .15s}
.gsearch:focus-within{border-color:var(--inv)}
.gsearch input{flex:1;border:none;background:none;outline:none;min-width:0;font-size:13.5px}
.tb-date{margin-left:auto;font:500 10.5px 'JetBrains Mono',monospace;color:var(--tx2);white-space:nowrap}
.icobtn{width:34px;height:34px;border:1.5px solid var(--bd);border-radius:var(--rr);display:flex;align-items:center;justify-content:center;background:var(--sf);transition:border-color .15s,transform .15s}
.icobtn:hover{border-color:var(--inv)}
.icobtn:active{transform:scale(.94)}
.lang-sw{display:flex;gap:2px;background:var(--el);border-radius:9px;padding:2px}
.lang-sw a{padding:4px 9px;border-radius:7px;font:700 10px 'JetBrains Mono',monospace;letter-spacing:.08em;color:var(--tx2);transition:background .15s,color .15s}
.lang-sw a.on{background:var(--inv);color:var(--on-inv)}
.view{padding:20px;max-width:1150px;width:100%;margin:0 auto 60px}
.view>*{animation:fadeUp .35s var(--ease) both}
.card{background:var(--sf);border:1.5px solid var(--bd);border-radius:var(--r);animation:fadeUp .35s var(--ease) both}
.card-h{display:flex;align-items:center;gap:9px;padding:11px 14px;border-bottom:1.5px solid var(--bd);font-weight:600;font-size:14px;font-family:'Sora',sans-serif}
.card-h .hico{color:var(--inv)}
.card-h .sp{flex:1}
.stack{display:flex;flex-direction:column;gap:14px;min-width:0}
.pg-h{display:flex;align-items:flex-end;justify-content:space-between;gap:12px;margin-bottom:14px;flex-wrap:wrap}
.pg-h h1{font:700 clamp(20px,2.6vw,25px) 'Sora',sans-serif;letter-spacing:-.025em}
.pg-h p{color:var(--tx2);margin-top:3px;font-size:13px}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;font-family:'Sora',sans-serif;font-weight:600;font-size:12.5px;height:35px;padding:0 15px;border-radius:var(--rr);border:1.5px solid transparent;white-space:nowrap;transition:transform .15s var(--ease),opacity .15s,background .15s,border-color .15s}
.btn.pri{color:var(--on-inv);background:linear-gradient(135deg,var(--inv),color-mix(in srgb,var(--inv) 80%,var(--bg)))}
.btn.pri:hover{opacity:.9;transform:translateY(-1px)}
.btn.pri:hover svg.ic{transform:translateX(3px)}
.btn.pri:active{transform:translateY(0) scale(.98)}
.btn.gho{border-color:var(--bd2);color:var(--tx)}
.btn.gho:hover{background:var(--el);border-color:var(--inv)}
.btn.sm{height:29px;padding:0 11px;font-size:11.5px;border-radius:10px}
.btn:disabled{opacity:.4;pointer-events:none}
.chip{display:inline-flex;align-items:center;gap:5px;font:600 9px 'JetBrains Mono',monospace;letter-spacing:.08em;text-transform:uppercase;padding:3px 8px;border-radius:20px;border:1.5px solid var(--bd2);color:var(--tx2)}
.chip .dot{width:4px;height:4px;border-radius:50%;background:currentColor;display:inline-block}
.chip.tk{font:700 11px 'JetBrains Mono',monospace;letter-spacing:.04em;text-transform:none;background:var(--inv);color:var(--on-inv);border:none;padding:3px 9px}
.chip.ur{border:1.5px solid var(--inv);color:var(--inv);font-weight:700}
.chip.gy{color:var(--tx2)}
.sla{font:700 9.5px 'JetBrains Mono',monospace;letter-spacing:.04em;padding:3px 8px;border-radius:7px;white-space:nowrap}
.sla.ok{border:1.5px solid var(--tx);color:var(--tx)}
.sla.wn{background:var(--tx2);color:var(--bg)}
.sla.ur{background:var(--inv);color:var(--on-inv)}
.sla.tr{border:1.5px dashed var(--bd2);color:var(--tx2)}
.row{display:flex;align-items:center;gap:11px;padding:10px 14px;border-bottom:1.5px solid var(--bd);transition:background .15s}
.row:last-child{border-bottom:none}
.row:hover{background:var(--el)}
.row .mid{flex:1;min-width:0}
.row .ref{font-weight:600;font-size:13.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.row .sub{display:flex;gap:5px;align-items:center;flex-wrap:wrap;color:var(--tx2);font-size:12px;margin-top:2px}
.row .rit{display:flex;align-items:center;gap:7px;flex:none}
.row .ck{width:16px;height:16px;accent-color:var(--inv);flex:none;cursor:pointer}
.empty{padding:32px 20px;text-align:center;color:var(--tx2);font-size:13.5px}
.empty b{display:block;color:var(--tx);margin-bottom:2px}
.abtn{width:30px;height:30px;border-radius:9px;display:inline-flex;align-items:center;justify-content:center;color:var(--tx2);transition:background .15s,color .15s,transform .15s}
.abtn:hover{background:var(--sf);color:var(--inv);outline:1.5px solid var(--inv-line)}
.abtn:active{transform:scale(.92)}
.abtn.ok{background:var(--inv);color:var(--on-inv);outline:none}
.abtn.ok:hover{opacity:.88}
.fgrid{display:grid;grid-template-columns:1fr 1fr;gap:12px 14px}
.fgrid .full{grid-column:1/-1}
.lb{display:block;font:600 9px 'JetBrains Mono',monospace;letter-spacing:.14em;text-transform:uppercase;color:var(--tx2);margin-bottom:5px}
.in{width:100%;background:var(--sf);border:1.5px solid var(--bd);border-radius:var(--rr);padding:9px 11px;outline:none;transition:border-color .18s var(--ease)}
.in:focus{border-color:var(--inv)}
textarea.in{resize:vertical;min-height:64px}
.seg{display:flex;background:var(--el);border-radius:var(--rr);padding:3px;gap:3px}
.seg button{flex:1;padding:7px 9px;border-radius:9px;font-weight:600;font-size:12.5px;color:var(--tx2);transition:background .18s var(--ease),color .18s}
.seg button.on{background:var(--inv);color:var(--on-inv)}
.qchip{font-size:11.5px;color:var(--tx);border:1.5px solid var(--bd2);border-radius:20px;padding:3px 10px;margin:0 5px 5px 0;transition:background .15s,border-color .15s,transform .15s}
.qchip:hover{background:var(--el);border-color:var(--inv)}
.qchip:active{transform:scale(.96)}
.filebox{display:block;border:1.5px dashed var(--bd2);border-radius:var(--rr);padding:12px;text-align:center;color:var(--tx2);font-size:13px;cursor:pointer;transition:border-color .15s,color .15s}
.filebox:hover{border-color:var(--inv);color:var(--inv)}
.pick{display:flex;gap:10px;align-items:center;padding:9px 12px;border:1.5px solid var(--bd);border-radius:var(--rr);margin-bottom:7px;cursor:pointer;transition:border-color .15s}
.pick:hover{border-color:var(--inv)}
.dash-grid{display:grid;grid-template-columns:1fr 260px;gap:14px;align-items:start}
.stats{display:grid;grid-template-columns:1fr 1fr}
.stat{display:flex;gap:10px;align-items:center;padding:12px 14px;border-bottom:1.5px solid var(--bd);border-right:1.5px solid var(--bd)}
.stat:nth-child(even){border-right:none}
.stat:nth-child(n+4){border-bottom:none}
.stat.wide{grid-column:1/-1;border-right:none}
.stat b{font:700 22px 'JetBrains Mono',monospace;display:block;line-height:1.1}
.stat b.ur{text-decoration:underline;text-decoration-color:var(--inv);text-underline-offset:4px}
.stat small{font:600 8px 'JetBrains Mono',monospace;letter-spacing:.12em;text-transform:uppercase;color:var(--tx2);display:block;margin-top:2px}
.sic{width:32px;height:32px;border-radius:10px;color:var(--inv);display:flex;align-items:center;justify-content:center;flex:none;background:color-mix(in srgb,var(--inv) 11%,transparent)}
.sic svg{width:15px;height:15px}
.chart{display:flex;align-items:flex-end;gap:6px;height:70px;padding:12px 14px 6px}
.chart .cw{flex:1;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;gap:5px;height:100%}
.chart .bar{width:100%;max-width:22px;border-radius:6px 6px 3px 3px;background:var(--inv);transform-origin:bottom;animation:growBar .5s var(--ease) both}
.chart .bar.dim{background:var(--bd2)}
.chart .cl{font:500 8px 'JetBrains Mono',monospace;color:var(--tx3)}
.act-row{display:flex;gap:10px;padding:9px 14px;border-bottom:1.5px solid var(--bd);font-size:12.5px}
.act-row:last-child{border-bottom:none}
.act-row .adot{width:7px;height:7px;border-radius:50%;background:var(--inv);margin-top:6px;flex:none}
.act-row time{display:block;font:500 9.5px 'JetBrains Mono',monospace;color:var(--tx3);margin-top:1px}
.tl{display:flex;flex-direction:column}
.tn{display:grid;grid-template-columns:24px 1fr;gap:0 12px;position:relative;padding-bottom:14px}
.tn:last-child{padding-bottom:2px}
.tn::before{content:"";position:absolute;left:7.5px;top:18px;bottom:0;width:1.5px;background:var(--bd2)}
.tn:last-child::before{display:none}
.tdot{width:16px;height:16px;border-radius:50%;border:2px solid var(--bd2);background:var(--sf);margin-top:2px;z-index:1}
.tn.done .tdot{background:var(--inv);border-color:var(--inv)}
.tn.cx .tdot{border-color:var(--tx);background:var(--bg)}
.tn .thead{display:flex;gap:8px;align-items:baseline;flex-wrap:wrap}
.tn .thead b{font-size:13px}
.tn .thead time{margin-left:auto;font:500 9.5px 'JetBrains Mono',monospace;color:var(--tx3)}
.tn .ttxt{font-size:12px;color:var(--tx2);margin-top:2px}
.tn .tinstr{font-size:12.5px;font-style:italic;margin-top:4px;padding:6px 10px;background:var(--el);border-left:3px solid var(--inv);border-radius:0 10px 10px 0}
.tn .tdur{font:500 9.5px 'JetBrains Mono',monospace;color:var(--tx3);margin-top:4px}
.toast{position:fixed;right:16px;bottom:16px;z-index:95;display:flex;gap:10px;align-items:center;background:var(--inv);color:var(--on-inv);padding:11px 15px;border-radius:12px;font-weight:500;font-size:13.5px;max-width:380px;border:1.5px solid var(--inv);animation:toastIn .35s var(--ease)}
.toast.ur{border-style:dashed}
.auth-wrap{min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:24px 16px;position:relative}
.auth-top{position:absolute;top:16px;right:18px;display:flex;gap:7px;align-items:center}
.acard{background:var(--sf);border:1.5px solid var(--bd);border-radius:22px;padding:22px 22px 20px;width:min(440px,100%);animation:popIn .5s var(--ease)}
.acard-brand{display:flex;align-items:center;gap:10px;margin-bottom:16px}
.acard-brand .sq{width:42px;height:42px;border-radius:13px;color:var(--on-inv);display:flex;align-items:center;justify-content:center;font:800 18px 'Sora',sans-serif;flex:none;background:linear-gradient(135deg,var(--inv),color-mix(in srgb,var(--inv) 78%,var(--bg)))}
.acard-brand b{font:700 17px 'Sora',sans-serif;display:block;line-height:1.1;letter-spacing:-.01em}
.acard-brand small{font:600 8px 'JetBrains Mono',monospace;letter-spacing:.16em;text-transform:uppercase;color:var(--tx2);display:block;margin-top:2px}
.at{font:700 21px 'Sora',sans-serif;letter-spacing:-.02em;margin-bottom:4px}
.asub{color:var(--tx2);font-size:13px;margin-bottom:14px;line-height:1.5}
.abig{width:100%;justify-content:center;height:44px;margin-top:16px;font-size:14px}
.arow{display:flex;gap:9px;flex-wrap:wrap}
.alinks{text-align:right;margin:9px 0 1px}
.alinks a{font-size:12px;font-weight:600;text-decoration:underline;text-underline-offset:3px}
.atabs{display:flex;gap:3px;background:var(--sf);border:1.5px solid var(--bd);border-radius:13px;padding:3px}
.atabs a{flex:1;text-align:center;padding:8px 6px;border-radius:10px;font-weight:600;font-size:12.5px;font-family:'Sora',sans-serif;color:var(--tx2);transition:background .2s var(--ease),color .2s}
.atabs a.on{background:var(--inv);color:var(--on-inv)}
.afoot{text-align:center;margin-top:14px;font-size:12px;color:var(--tx2)}
.afoot a{font-weight:600;text-decoration:underline;text-underline-offset:3px}
.field{position:relative}
.field>.lic{position:absolute;left:7px;top:50%;transform:translateY(-50%);width:26px;height:26px;border-radius:8px;background:var(--el);color:var(--tx3);display:flex;align-items:center;justify-content:center;pointer-events:none;transition:color .2s,background .2s}
.field>.lic svg{width:14px;height:14px;display:block}
.field:focus-within>.lic{color:var(--inv);background:color-mix(in srgb,var(--inv) 11%,transparent)}
.in.has-ic{padding-left:42px}
.in.has-tg{padding-right:44px}
select.in.has-ic{padding-left:42px}
.pw-toggle{position:absolute;right:5px;top:50%;transform:translateY(-50%);width:32px;height:32px;border-radius:9px;display:flex;align-items:center;justify-content:center;color:var(--tx3);transition:color .2s,background .2s}
.pw-toggle:hover{color:var(--inv);background:var(--el)}
.pw-toggle svg{width:19px;height:19px;transition:transform .18s var(--ease)}
.pw-toggle:active svg{transform:scale(.85)}
.pw-toggle .lk-shackle{transition:transform .35s cubic-bezier(.34,1.56,.64,1);transform-origin:15.5px 7.5px}
.pw-toggle.open .lk-shackle{transform:translate(-2px,-1px) rotate(-42deg)}
.pw-toggle .lk-key{transition:opacity .2s}
.pw-toggle.open .lk-key{opacity:.35}
@media print{body *{visibility:hidden}.print-zone,.print-zone *{visibility:visible}
.print-zone{position:absolute;left:0;top:0;width:100%;padding:14mm 16mm;background:#fff;color:#000}
.print-zone .btn{display:none}}
@media (max-width:960px){.dash-grid{grid-template-columns:1fr}}
@media (max-width:820px){
.sidebar{position:fixed;left:0;top:0;transform:translateX(-103%);transition:transform .25s var(--ease)}
.sidebar.open{transform:none}.backdrop.on{display:block}.burger{display:flex}.tb-date{display:none}
.view{padding:15px 13px 70px}.fgrid{grid-template-columns:1fr}
.row{flex-wrap:wrap}.row .rit{width:100%;justify-content:flex-end;padding-top:3px}}
CSS;

 $JS = <<<'JS'
function refreshIcons(){ try{ if(window.lucide) lucide.createIcons({attrs:{'stroke-width':1.7}}); }catch(e){} }
document.addEventListener('DOMContentLoaded', function(){
  document.querySelectorAll('.pw-toggle').forEach(function(btn){
    btn.addEventListener('click', function(){
      var inp=document.getElementById(btn.dataset.toggle); if(!inp) return;
      var show=(inp.type==='password');
      inp.type=show?'text':'password';
      btn.classList.toggle('open', show);
    });
  });
  var bt=document.getElementById('btnTema');
  if(bt) bt.addEventListener('click', function(){
    var h=document.documentElement, t2=h.dataset.theme==='dark'?'light':'dark';
    h.dataset.theme=t2; document.cookie='tema='+t2+';path=/;max-age=31536000';
    this.innerHTML='<i data-lucide="'+(t2==='dark'?'sun':'moon')+'"></i>'; refreshIcons();
  });
  var bg=document.getElementById('burger'), sd=document.getElementById('sidebar'), bk=document.getElementById('backdrop');
  if(bg&&sd&&bk){
    bg.addEventListener('click', function(){ sd.classList.toggle('open'); bk.classList.toggle('on'); });
    bk.addEventListener('click', function(){ sd.classList.remove('open'); bk.classList.remove('on'); });
  }
  document.querySelectorAll('form[data-confirm]').forEach(function(f){
    f.addEventListener('submit', function(e){ if(!confirm(f.dataset.confirm==='?'?'Confirm? / ¿Confirmar?':f.dataset.confirm)) e.preventDefault(); });
  });
  document.addEventListener('click', function(e){
    var c=e.target.closest('.qchip'); if(!c) return;
    var ta=document.getElementById(c.dataset.target); if(ta) ta.value=c.dataset.prov;
  });
  document.querySelectorAll('.seg[data-name]').forEach(function(seg){
    seg.querySelectorAll('button').forEach(function(b){
      b.addEventListener('click', function(){
        seg.querySelectorAll('button').forEach(function(x){ x.classList.remove('on'); });
        b.classList.add('on');
        var h=document.getElementById(seg.dataset.name+'Input'); if(h) h.value=b.dataset.v;
        if(seg.dataset.name==='tipo'){
          var w=document.getElementById('wrapCite');
          if(w) w.style.display=(b.dataset.v==='con_cite')?'block':'none';
        }
      });
    });
  });
  var ai=document.getElementById('adjInput');
  if(ai) ai.addEventListener('change', function(){
    var l=document.getElementById('adjList'); if(!l) return;
    var out=''; for(var i=0;i<ai.files.length;i++){ var ff=ai.files[i];
      out+='<div>• '+ff.name+' ('+Math.round(ff.size/1024)+' KB)</div>'; }
    l.innerHTML=out;
  });
  document.querySelectorAll('.ck-group').forEach(function(k){
    k.addEventListener('change', function(){
      var n=document.querySelectorAll('.ck-group:checked').length;
      var b=document.getElementById('btnGroup'); if(b) b.disabled=(n<2);
      var s=document.getElementById('nSel'); if(s) s.textContent=n;
    });
  });
  var t2=document.getElementById('toast'); if(t2) setTimeout(function(){ t2.remove(); }, 4000);
  refreshIcons();
});
window.addEventListener('load', refreshIcons);
JS;

 $head = '<!DOCTYPE html><html lang="'.e($LANG).'" data-theme="'.e($tema).'"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>TRÁMITE</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;600;700&display=swap" rel="stylesheet">
<style>'.$CSS.'</style></head><body>';

/* ---------- Salida pantallas de acceso ---------- */
if($independiente){
  echo $head;
  echo '<div class="auth-wrap">
    <div class="auth-top">'.$lsw.'
      <button class="icobtn" id="btnTema" type="button" aria-label="Theme"><i data-lucide="'.($tema==='dark'?'sun':'moon').'"></i></button>
    </div>';
  if($p==='login' || $p==='registro'){
    echo '<div class="atabs" style="width:min(440px,100%);margin-bottom:12px">
      <a href="?p=login"'.($p==='login'?' class="on"':'').'>'.t('tab_login').'</a>
      <a href="?p=registro"'.($p==='registro'?' class="on"':'').'>'.t('tab_register').'</a>
    </div>';
  }
  if($f) echo '<div class="toast'.($f['type']==='err'?' ur':'').'" style="position:static;margin-bottom:14px;animation:none"><span>'.$f['msg'].'</span></div>';
  echo $content;
  if($p==='login') echo '<p class="afoot">'.t('foot_reg').' <a href="?p=registro">'.t('foot_reg2').'</a> '.t('foot_reg3').'.</p>';
  if($p==='registro') echo '<p class="afoot">'.t('foot_login').' <a href="?p=login">'.t('foot_login2').'</a>.</p>';
  if($p==='recuperar') echo '<p class="afoot">'.t('foot_rec').' <a href="?p=login">'.t('foot_rec2').'</a>.</p>';
  echo '</div>
  <script src="https://unpkg.com/lucide@latest" defer></script><script>'.$JS.'</script></body></html>';
  exit;
}

/* ---------- Salida aplicación ---------- */
 $cnt = bandeja_counts($uid);
 $nav = function($key,$icoL,$lbl,$badge=0) use ($p){
  return '<a'.($p===$key?' class="on"':'').' href="?p='.$key.'"><i data-lucide="'.$icoL.'"></i>'.$lbl.($badge?'<span class="cnt">'.(int)$badge.'</span>':'').'</a>';
};
echo $head;
?>
<div class="layout">
  <aside class="sidebar" id="sidebar">
    <a class="brand" href="?p=dashboard"><span class="sq">T</span><span><b>TRÁMITE</b><small><?php echo e(t('tag')); ?></small></span></a>
    <nav class="nav">
      <div class="nav-t"><?php echo t('nav_oper'); ?></div>
      <?php echo $nav('dashboard','gauge',t('nav_dash')); ?>
      <?php echo $nav('nueva','file-plus-2',t('nav_new')); ?>
      <?php echo $nav('cites','stamp',t('nav_cites')); ?>
      <?php if(($me['role'] ?? '')==='admin'): ?>
      <div class="nav-t"><?php echo t('nav_admin'); ?></div>
      <?php echo $nav('usuarios','users-round',t('nav_users')); ?>
      <?php echo $nav('oficinas','building-2',t('nav_offices')); ?>
      <?php endif; ?>
      <div class="nav-t"><?php echo t('nav_trays'); ?></div>
      <?php echo $nav('entrantes','inbox',t('nav_inbox'),$cnt['entrantes']); ?>
      <?php echo $nav('pendientes','hourglass',t('nav_pending'),$cnt['pendientes']); ?>
      <?php echo $nav('enviados','send',t('nav_sent')); ?>
      <?php echo $nav('archivo','folder-archive',t('nav_archive')); ?>
    </nav>
    <div class="side-u">
      <div class="su-card">
        <span class="ava"><?php echo e(strtoupper(mb_substr($me['name'],0,1))); ?></span>
        <span><b><?php echo e($me['name']); ?></b></span>
      </div>
      <a class="logout" href="?p=logout"><i data-lucide="log-out"></i> <?php echo t('logout'); ?></a>
    </div>
  </aside>
  <div class="backdrop" id="backdrop"></div>
  <div class="main">
    <header class="topbar">
      <button class="burger" id="burger" type="button" aria-label="Menu"><i data-lucide="menu"></i></button>
      <form class="gsearch" action="?" method="get">
        <input type="hidden" name="p" value="buscar">
        <i data-lucide="search"></i>
        <input type="text" name="q" placeholder="<?php echo e(t('search_ph')); ?>" value="<?php echo e($_GET['q']??''); ?>">
      </form>
      <?php echo $lsw; ?>
      <span class="tb-date"><?php echo e(fmt_date(date('Y-m-d H:i'),true)); ?></span>
      <button class="icobtn" id="btnTema" type="button" aria-label="Theme"><i data-lucide="<?php echo $tema==='dark'?'sun':'moon'; ?>"></i></button>
    </header>
    <main class="view"><?php echo $content; ?></main>
  </div>
</div>
<?php if($f): ?>
<div class="toast<?php echo $f['type']==='err'?' ur':''; ?>" id="toast"><span><?php echo $f['msg']; ?></span></div>
<?php endif; ?>
<script src="https://unpkg.com/lucide@latest" defer></script>
<script><?php echo $JS; ?></script>
</body></html>