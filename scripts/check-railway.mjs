import definition from '../.railway/railway.ts';
import { createRailwayContext, validateGraph } from 'railway/iac';
const project = await definition(createRailwayContext({environment:'production'}));
// SDK resource constructors validate fields. Ensure all required services and volume intent exist.
if (!project.resources || project.resources.length !== 5) throw new Error('Expected MySQL, evidence volume, web, worker and scheduler.');
console.log('Railway project configuration evaluated: 5 resources. No resources deployed.');
