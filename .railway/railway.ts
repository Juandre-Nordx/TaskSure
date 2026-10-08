import { defineRailway, project, service, mysql, volume, github } from 'railway/iac';
export default defineRailway((ctx) => {
 const db = mysql('mysql');
 const evidence = volume('task-evidence', {region:'europe-west4',sizeMB:10240});
 const source = github('Juandre-Nordx/TaskSure', {branch:'main'});
 const env = {
  APP_NAME:'TaskSure', APP_ENV:'production', APP_DEBUG:'false',
  APP_KEY:ctx.shared.APP_KEY, APP_URL:ctx.shared.APP_URL,
  DB_CONNECTION:'mysql', DB_HOST:db.env.MYSQLHOST, DB_PORT:db.env.MYSQLPORT,
  DB_DATABASE:db.env.MYSQLDATABASE, DB_USERNAME:db.env.MYSQLUSER, DB_PASSWORD:db.env.MYSQLPASSWORD,
  SESSION_DRIVER:'database', SESSION_SECURE_COOKIE:'true', CACHE_STORE:'database', QUEUE_CONNECTION:'database',
  LOG_CHANNEL:'stderr', TASK_EMAIL_ENABLED:'false', MAIL_MAILER:'log', UPLOAD_MAX_KB:'10240', REMINDER_MINUTES:'60',
 };
 const build = {builder:'DOCKERFILE',dockerfilePath:'Dockerfile'} as const;
 const web = service('web',{source,build,env:{...env,PROCESS_ROLE:'web',UPLOAD_STORAGE_PATH:'/data/uploads'},replicas:1,regions:{'europe-west4':1},preDeploy:'php artisan migrate --force && php artisan db:seed --force',healthcheck:'/health',healthcheckTimeout:120,volumeMounts:{'/data':evidence}});
 const worker=service('worker',{source,build,env:{...env,PROCESS_ROLE:'worker'},replicas:1});
 const scheduler=service('scheduler',{source,build,env:{...env,PROCESS_ROLE:'scheduler'},replicas:1});
 return project('TaskSure',{resources:[db,evidence,web,worker,scheduler]});
});
