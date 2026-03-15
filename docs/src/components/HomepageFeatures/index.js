import React from 'react';
import clsx from 'clsx';
import styles from './styles.module.css';

const FeatureList = [
  {
    title: 'Case Management',
    description: (
      <>
        Manage cases with configurable case types, status lifecycles, deadlines, and extensions — all built on Nextcloud.
      </>
    ),
  },
  {
    title: 'Tasks & Roles',
    description: (
      <>
        Assign tasks, track participants, and manage handler roles with a complete CMMN-inspired workflow engine.
      </>
    ),
  },
  {
    title: 'Built on OpenRegister',
    description: (
      <>
        All data stored as flexible OpenRegister objects. No custom database tables — just schemas, registers, and standards.
      </>
    ),
  },
];

function Feature({title, description}) {
  return (
    <div className={clsx('col col--4')}>
      <div className="text--center padding-horiz--md">
        <h3>{title}</h3>
        <p>{description}</p>
      </div>
    </div>
  );
}

export default function HomepageFeatures() {
  return (
    <section className={styles.features}>
      <div className="container">
        <div className="row">
          {FeatureList.map((props, idx) => (
            <Feature key={idx} {...props} />
          ))}
        </div>
      </div>
    </section>
  );
}
