module.exports = {
  apps: [
    {
      name: 'invoice-scheduler',
      script: 'artisan',
      args: 'schedule:work',
      interpreter: 'php',
      instances: 1,
      autorestart: true,
      watch: false,
      max_memory_restart: '1G'
    },
    {
      name: 'invoice-queue-worker',
      script: 'artisan',
      args: 'queue:work --sleep=3 --tries=3',
      interpreter: 'php',
      instances: 1,
      autorestart: true,
      watch: false,
      max_memory_restart: '1G'
    }
  ]
};
