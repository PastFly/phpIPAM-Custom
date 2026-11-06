SELECT CONCAT(
 COALESCE(SUM(state='pending'),0),'|',COALESCE(SUM(state='running'),0),'|',
 COALESCE(SUM(state IN ('succeeded','failed','expired','obsolete') AND COALESCE(finished_at,updated_at)<=UNIX_TIMESTAMP()+1200-86400),0),'|',
 (SELECT COUNT(*) FROM custom_dns_connections WHERE id<>'33b6fade9323f15aa7b1f56a8a865a9d' AND enabled=1),'|',IF(COUNT(*)>=384,1,0),'|',
 (SELECT COUNT(*) FROM custom_dns_control_jobs j
  JOIN custom_dns_collector_assignments a ON a.connection_id=j.connection_id AND a.collector_id=j.collector_id AND a.revision=j.assignment_revision
  JOIN custom_dns_connections c ON c.id=j.connection_id AND c.revision=j.configuration_revision AND c.binding_epoch=j.binding_epoch
  JOIN custom_dns_collectors k ON k.id=j.collector_id
  WHERE ((j.id='0f5fbf81fc71164cc7c624fc6101925f' AND j.job_type='list_zones' AND j.created_at=1793966475) OR (j.id='edfcd75935083e51c4536a390174057e' AND j.job_type='test_connection' AND j.created_at=1793966491)) AND j.state='pending' AND j.collector_id='f57057d6677e1ca7a032aab8b68796ac' AND j.connection_id='33b6fade9323f15aa7b1f56a8a865a9d'
   AND j.expires_at=j.created_at+300 AND j.expires_at<UNIX_TIMESTAMP() AND j.updated_at=j.created_at AND j.updated_by=4
   AND j.attempt_count=0 AND j.lease_id IS NULL AND j.lease_expires_at IS NULL AND j.credential_delivered_at IS NULL
   AND j.result_code IS NULL AND j.result_payload IS NULL AND j.finished_at IS NULL AND j.credential_version IS NULL
   AND c.enabled=0 AND c.credential_mode='collector_identity' AND c.server='srv-dc01.rbank.local' AND k.revoked=0
   AND (SELECT COUNT(*) FROM custom_dns_control_events e WHERE e.object_id=j.id AND e.action='control_job_queued')=1
   AND (SELECT COUNT(*) FROM custom_dns_control_events e WHERE e.object_id=j.id AND e.action='control_job_queued' AND e.actor_id=4)=1
   AND (SELECT COUNT(*) FROM custom_dns_control_events e WHERE e.object_id=j.id AND e.action IN ('control_job_cli_queued','autonomous_job_queued'))=0)) AS queue_state
 FROM custom_dns_control_jobs;
