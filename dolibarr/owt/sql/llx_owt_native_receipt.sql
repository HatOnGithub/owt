CREATE TABLE llx_owt_native_receipt (
    entity integer NOT NULL,
    operation_id varchar(64) NOT NULL,
    bindings text NOT NULL,
    PRIMARY KEY (entity, operation_id)
) ENGINE=innodb;
