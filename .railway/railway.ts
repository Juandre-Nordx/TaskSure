import { defineRailway, project, service, postgres, volume, github } from 'railway/iac';
export default defineRailway((ctx) => {
 const db = postgres('postgres');
 const evidence = volume('task-evidence', {region:'europe-west4',sizeMB:10240});
 const source = github('Juandre-Nordx/TaskSure', {branch:'main'});
 const env = {
  APP_NAME:'TaskSure', APP_ENV:'production', APP_DEBUG:'false',
  APP_KEY:ctx.shared.APP_KEY, APP_URL:ctx.shared.APP_URL,
  DB_CONNECTION:'pgsql', DB_HOST:db.env.PGHOST, DB_PORT:db.env.PGPORT,
  DB_DATABASE:db.env.PGDATABASE, DB_USERNAME:db.env.PGUSER, DB_PASSWORD:db.env.PGPASSWORD,
  SESSION_DRIVER:'database', SESSION_ENCRYPT:'true', SESSION_SECURE_COOKIE:'true', CACHE_STORE:'database', QUEUE_CONNECTION:'database', DB_QUEUE_RETRY_AFTER:'180',
  LOG_CHANNEL:'stderr', TASK_EMAIL_ENABLED:'false', MAIL_MAILER:'log', UPLOAD_MAX_KB:'10240', REMINDER_MINUTES:'60',
 };
 const build = {builder:'DOCKERFILE',dockerfilePath:'Dockerfile'} as const;
 const web = service('web',{source,build,env:{...env,PROCESS_ROLE:'web',UPLOAD_STORAGE_PATH:'/data/uploads'},replicas:1,regions:{'europe-west4':1},preDeploy:'php artisan migrate --force && php artisan db:seed --force',healthcheck:'/health',healthcheckTimeout:120,volumeMounts:{'/data':evidence}});
 const worker=service('worker',{source,build,env:{...env,PROCESS_ROLE:'worker'},replicas:1});
 const scheduler=service('scheduler',{source,build,env:{...env,PROCESS_ROLE:'scheduler'},replicas:1});
 return project('TaskSure',{resources:[db,evidence,web,worker,scheduler]});
});
