(()=>{"use strict";const e=window.wp.element,t=window.wp.components,n=window.wp.data,a=(0,n.createReduxStore)("plugins/contact-form-7",{reducer:(e,t)=>e});(0,n.register)(a);const o=()=>(0,e.createElement)(t.TabPanel,{tabs:[{name:"tab1",title:"tab1"},{name:"tab2",title:"tab2"}]},(t=>(0,e.createElement)("p",null,t.title)));window.addEventListener("load",(function(){(0,e.createRoot)(document.querySelector("#contact-form-editor")).render((0,e.createElement)(o,null))}),!1)})();