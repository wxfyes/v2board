const fs = require('fs');
const umiPath = './public/assets/admin/umi.js';
let c = fs.readFileSync(umiPath, 'utf-8');

// Check current state
console.log('Has toggleShadowBan:', c.includes('toggleShadowBan'));
console.log('Has delUser:', c.includes('delUser(e)'));

// Find the exact pattern around delUser
let idx = c.indexOf('delUser(e) {');
if (idx > 0) {
    console.log('Context around delUser:');
    console.log(JSON.stringify(c.substring(idx - 50, idx + 100)));
}
